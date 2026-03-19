<?php

namespace App\Command;

use App\Exception\InvalidArgumentException;
use App\Message\ProcessPayoutMessage;
use App\Message\ProcessTransferMessage;
use App\Service\ConfigService;
use App\Service\MiraklClient;
use App\Service\SellerSettlementService;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

class SellerSettlementCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected static $defaultName = 'connector:dispatch:process-payout';

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
     * @var SellerSettlementService
     */
    private $sellerSettlementService;

    /**
     * @var bool
     */
    private $enableCommissionTaxFromInvoices;

    public function __construct(
        MessageBusInterface $bus,
        ConfigService $configService,
        MiraklClient $miraklClient,
        SellerSettlementService $sellerSettlementService,
        $enableCommissionTaxFromInvoices
    ) {
        $this->bus = $bus;
        $this->configService = $configService;
        $this->miraklClient = $miraklClient;
        $this->sellerSettlementService = $sellerSettlementService;
        $this->enableCommissionTaxFromInvoices = $enableCommissionTaxFromInvoices;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('mirakl_shop_id', InputArgument::OPTIONAL);
    }

    /**
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->logger->info('starting');
        // Shop ID passed as argument
        $shopId = $input->getArgument('mirakl_shop_id');
        if (is_numeric($shopId)) {
            $this->processProvidedShopId((int) $shopId);
            $this->logger->info('job succeeded');

            return 0;
        }

        // Start with transfers and payouts that couldn't be completed before, 10 at a time
        $this->processBacklog();

        // Now up to 100 new invoices
        $this->processNewInvoices();

        $this->logger->info('job succeeded');

        return 0;
    }

    private function processProvidedShopId(int $shopId): void
    {
        $this->logger->info('Executing provided shop', ['shop_id' => $shopId]);
        $invoices = $this->miraklClient->listInvoicesByShopId($shopId);

        $this->dispatchTransfers(
            $this->sellerSettlementService->createTransfersFromInvoices($invoices)
        );

        $this->dispatchPayouts(
            $this->sellerSettlementService->getPayoutsFromInvoices($invoices, $this->miraklClient)
        );
    }

    private function processBacklog(): void
    {
        $this->logger->info('Executing backlog for retriable transfers and payouts');

        $this->logger->info('Process backlog - Variable enableCommissionTaxFromInvoices enabled: ' . ($this->enableCommissionTaxFromInvoices ? 'yes' : 'no'));
        if ($this->enableCommissionTaxFromInvoices) {
            $this->logger->info('Processing retriable commission and tax transfers');
            $retriableCommissionTaxTransfers = $this->sellerSettlementService->getRetriableCommissionTaxTransfers();
            if (!empty($retriableCommissionTaxTransfers)) {
                $commissionTaxFirstInvoiceDate = $this->getFirstInvoiceDateForCommissionAndTax($retriableCommissionTaxTransfers);
                $commissionTaxInvoices = $this->miraklClient->listInvoicesByDate($commissionTaxFirstInvoiceDate);
                $commissionTaxTransfersByInvoiceId = $this->sellerSettlementService
                    ->updateCommissionTaxTransfersFromInvoices($retriableCommissionTaxTransfers, $commissionTaxInvoices);
                $this->dispatchTransfers($commissionTaxTransfersByInvoiceId);
            } else {
                $this->logger->info('No retriable commission and tax transfers');
            }
        }

        $retriableTransfers = $this->sellerSettlementService->getRetriableTransfers();
        $retriablePayouts = $this->sellerSettlementService->getRetriablePayouts();

        if (empty($retriableTransfers) && empty($retriablePayouts)) {
            $this->logger->info('No backlog for transfers and payouts');
            return;
        }

        // No invoice ID filter, let's use the earliest creation date
        $firstDateCreated = $this->getFirstInvoiceDate($retriableTransfers, $retriablePayouts);
        $invoices = $this->miraklClient->listInvoicesByDate($firstDateCreated);

        $transfersByInvoiceId = $this->sellerSettlementService
            ->updateTransfersFromInvoices($retriableTransfers, $invoices);
        $this->dispatchTransfers($transfersByInvoiceId);

        $payouts = $this->sellerSettlementService
            ->updatePayoutsFromInvoices($retriablePayouts, $invoices, $this->miraklClient);
        $this->dispatchPayouts($payouts);
    }

    private function getFirstInvoiceDate(array $transfersByInvoiceId, array $payouts): string
    {
        $createdDates = array_map(
            function ($o) {
                return $o->getMiraklCreatedDate();
            },
            array_merge($this->flattenTransfers($transfersByInvoiceId), $payouts)
        );

        sort($createdDates);

        return MiraklClient::getStringFromDatetime(current($createdDates));
    }

    private function getFirstInvoiceDateForCommissionAndTax(array $transfersByInvoiceId): string
    {
        $createdDates = array_map(
            function ($o) {
                return $o->getMiraklCreatedDate();
            },
            $this->flattenTransfers($transfersByInvoiceId)
        );

        sort($createdDates);

        return MiraklClient::getStringFromDatetime(current($createdDates));
    }

    private function processNewInvoices(): void
    {
        $checkpoint = $this->configService->getSellerSettlementCheckpoint() ?? '';
        $this->logger->info('Executing for recent invoices, checkpoint: ' . $checkpoint);
        if ($checkpoint) {
            $invoices = $this->miraklClient->listInvoicesByDate($checkpoint);
        } else {
            $invoices = $this->miraklClient->listInvoices();
        }

        if (empty($invoices)) {
            $this->logger->info('No new invoice found since checkpoint: ' . $checkpoint);
            return;
        }

        $this->logger->info('Process new invoices - Variable enableCommissionTaxFromInvoices enabled: ' . ($this->enableCommissionTaxFromInvoices ? 'yes' : 'no'));
        if ($this->enableCommissionTaxFromInvoices) {
            $this->logger->info('Processing commission and tax transfers from invoices');
            $this->dispatchTransfers(
                $this->sellerSettlementService->createCommissionTaxTransfersFromInvoices($invoices)
            );
        }

        $this->dispatchTransfers(
            $this->sellerSettlementService->createTransfersFromInvoices($invoices)
        );

        $this->dispatchPayouts(
            $this->sellerSettlementService->getPayoutsFromInvoices($invoices, $this->miraklClient)
        );

        $checkpoint = $this->updateCheckpoint($invoices, $checkpoint);
        $this->configService->setSellerSettlementCheckpoint($checkpoint);
        $this->logger->info('Setting new checkpoint: ' . $checkpoint);
    }

    // Return the last valid date_created or the current checkpoint
    private function updateCheckpoint(array $invoices, ?string $checkpoint): ?string
    {
        $invoices = array_reverse($invoices);
        foreach ($invoices as $invoice) {
            try {
                MiraklClient::getDatetimeFromString($invoice['date_created']);

                return $invoice['date_created'];
            } catch (InvalidArgumentException $e) {
                // Shouldn't happen, see MiraklClient::getDatetimeFromString
            }
        }

        return $checkpoint;
    }

    private function dispatchTransfers(array $transfersByInvoiceId): void
    {
        foreach ($this->flattenTransfers($transfersByInvoiceId) as $transfer) {
            if ($transfer->isDispatchable()) {
                $this->bus->dispatch(new ProcessTransferMessage(
                    $transfer->getId()
                ));
            }
        }
    }

    private function dispatchPayouts(array $payouts): void
    {
        foreach ($payouts as $payout) {
            if ($payout->isDispatchable()) {
                $this->bus->dispatch(new ProcessPayoutMessage(
                    $payout->getId()
                ), [new DelayStamp(72 * 60 * 60 * 1000)]);
            }
        }
    }

    private function flattenTransfers(array $transfersByInvoiceId): array
    {
        $flat = [];
        foreach ($transfersByInvoiceId as $transfers) {
            $flat = array_merge($flat, array_values($transfers));
        }

        return $flat;
    }
}
