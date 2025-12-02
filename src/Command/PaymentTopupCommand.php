<?php

namespace App\Command;

use App\Message\ProcessTopupMessage;
use App\Service\ConfigService;
use App\Service\MiraklClient;
use App\Service\StripeTopupService;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class PaymentTopupCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected static $defaultName = 'connector:dispatch:process-topups';

    /**
     * @var MessageBusInterface
     */
    private $bus;

    /**
     * @var ConfigService
     */
    private $configService;

    /**
     * @var MiraklClient
     */
    private $miraklClient;

    /**
     * @var StripeTopupService
     */
    private $stripeTopupService;

    /**
     * @param MessageBusInterface $bus
     * @param ConfigService $configService
     * @param MiraklClient $miraklClient
     * @param StripeTopupService $stripeTopupService
     */
    public function __construct(
        MessageBusInterface $bus,
        ConfigService $configService,
        MiraklClient $miraklClient,
        StripeTopupService $stripeTopupService
    ) {
        $this->bus = $bus;
        $this->configService = $configService;
        $this->miraklClient = $miraklClient;
        $this->stripeTopupService = $stripeTopupService;
        parent::__construct();
    }

    /**
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->logger->info('Topup Job - starting');

        // Process backlog first
        $this->processBacklog();

        // Then process new invoices
        $this->processNewInvoices();

        $this->logger->info('Topup Job - job succeeded');

        return 0;
    }

    private function processBacklog(): void
    {
        $this->logger->info('Topup Job - Executing backlog');

        $onHoldInvoices = $this->stripeTopupService->getInvoicesOnHold();

        if (empty($onHoldInvoices)) {
            $this->logger->info('Topup Job - No backlog invoices on hold');
            return;
        }

        $this->logger->info('Topup Job - Invoices on hold: ' . count($onHoldInvoices));

        // Get the earliest dateCreatedFromMirakl from onHold invoices
        $earliestDate = null;
        foreach ($onHoldInvoices as $invoiceData) {
            $date = $invoiceData->getDateCreatedFromMirakl();
            if ($date && (!$earliestDate || $date < $earliestDate)) {
                $earliestDate = $date;
            }
        }

        $this->logger->info('Topup Job - Earliest date from on-hold invoices: ' . ($earliestDate ? $earliestDate->format('Y-m-d H:i:s') : 'none'));

        // Fetch Mirakl invoices from that date
        $miraklInvoices = $earliestDate
            ? $this->miraklClient->listInvoicesByDate($earliestDate->format('Y-m-d\TH:i:s\Z'))
            : $this->miraklClient->listInvoices();

        if (empty($miraklInvoices)) {
            $this->logger->info('Topup Job - No Mirakl invoices found since earliest date');
            return;
        }

        // Build list of invoice numbers from on-hold invoices
        $onHoldInvoiceNumbers = array_map(fn($i) => $i->getInvoiceNumber(), $onHoldInvoices);

        // Keep only Mirakl invoices that match the on-hold ones
        $filteredInvoices = array_filter($miraklInvoices, fn($inv) => in_array($inv['invoice_id'], $onHoldInvoiceNumbers));

        if (empty($filteredInvoices)) {
            $this->logger->info('Topup Job - No matching Mirakl invoices for on-hold invoices');
            return;
        }

        $topups = $this->stripeTopupService->createTopupsFromOnHoldInvoices($filteredInvoices, $this->miraklClient);

        $this->dispatchTopups($topups);

        $this->logger->info('Topup Job - Backlog processed');
    }

    private function processNewInvoices(): void
    {
        $this->logger->info('Topup Job - Executing new invoices');

        $checkpoint = $this->configService->getSellerSettlementCheckpoint() ?? '';
        $this->logger->info('Topup Job - Executing for recent invoices, checkpoint: ' . $checkpoint);
        if ($checkpoint) {
            $invoices = $this->miraklClient->listInvoicesByDate($checkpoint);
        } else {
            $invoices = $this->miraklClient->listInvoices();
        }


        if (empty($invoices)) {
            $this->logger->info('Topup Job - No new invoices');

            return;
        }

        $this->dispatchTopups(
            $this->stripeTopupService->getTopupsFromInvoices($invoices, $this->miraklClient)
        );

        $this->logger->info('Topup Job - New invoices processed');
    }

    private function dispatchTopups($topups): void
    {
        foreach ($topups as $topup) {
            if ($topup->isDispatchable()) {
                $this->bus->dispatch(new ProcessTopupMessage(
                    $topup->getId()
                ));
            }
        }
    }
}
