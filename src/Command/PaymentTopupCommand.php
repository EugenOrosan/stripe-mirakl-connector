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

        // Now up to 100 new invoices
        $this->processNewInvoices();

        $this->logger->info('Topup Job - job succeeded');

        return 0;
    }

    private function processNewInvoices(): void
    {
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
