<?php

namespace App\Service;

use App\Entity\StripePayout;
use App\Entity\StripeTopup;
use App\Entity\StripeTransfer;
use App\Factory\StripePayoutFactory;
use App\Factory\StripeTransferFactory;
use App\Repository\StripePayoutRepository;
use App\Repository\StripeTopupRepository;
use App\Repository\StripeTransferRepository;

class SellerSettlementService
{
    /**
     * @var StripePayoutFactory
     */
    private $stripePayoutFactory;

    /**
     * @var StripeTransferFactory
     */
    private $stripeTransferFactory;

    /**
     * @var StripePayoutRepository
     */
    private $stripePayoutRepository;

    /**
     * @var StripeTransferRepository
     */
    private $stripeTransferRepository;

    /**
     * @var StripeTopupRepository
     */
    private $stripeTopupRepository;

    public function __construct(
        StripePayoutFactory $stripePayoutFactory,
        StripeTransferFactory $stripeTransferFactory,
        StripePayoutRepository $stripePayoutRepository,
        StripeTransferRepository $stripeTransferRepository,
        StripeTopupRepository $stripeTopupRepository
    ) {
        $this->stripePayoutFactory = $stripePayoutFactory;
        $this->stripeTransferFactory = $stripeTransferFactory;
        $this->stripePayoutRepository = $stripePayoutRepository;
        $this->stripeTransferRepository = $stripeTransferRepository;
        $this->stripeTopupRepository = $stripeTopupRepository;
    }

    /**
     * @return array [ invoice_id => StripeTransfer[] ]
     */
    public function getRetriableTransfers(): array
    {
        return $this->stripeTransferRepository->findRetriableInvoiceTransfers();
    }

    /**
     * @return array [ invoice_id => StripeTransfer[] ]
     */
    public function getTransfersFromInvoices(array $invoices): array
    {
        // Retrieve existing StripeTransfers with provided invoice IDs
        $existingTransfers = $this->stripeTransferRepository
            ->findTransfersByInvoiceIds(array_keys($invoices));

        $transfersByInvoiceId = [];
        foreach ($invoices as $invoice) {
            foreach (StripeTransfer::getInvoiceTypes() as $type) {
                $invoiceId = $invoice['invoice_id'];
                if (isset($existingTransfers[$invoiceId][$type])) {
                    $transfer = $existingTransfers[$invoiceId][$type];
                    if (!$transfer->isRetriable()) {
                        continue;
                    }

                    // Use existing transfer
                    $transfer = $this->stripeTransferFactory
                        ->updateFromInvoice($transfer, $invoice, $type);
                } else {
                    // Create new transfer
                    $transfer = $this->stripeTransferFactory
                        ->createFromInvoice($invoice, $type);
                    $this->stripeTransferRepository->persist($transfer);
                }

                if (!isset($transfersByInvoiceId[$invoiceId])) {
                    $transfersByInvoiceId[$invoiceId] = [];
                }

                $transfersByInvoiceId[$invoiceId][$type] = $transfer;
            }
        }

        // Save
        $this->stripeTransferRepository->flush();

        return $transfersByInvoiceId;
    }

    public function createTransfersFromInvoices(array $invoices): array
    {
        // Retrieve existing StripeTransfers with provided invoice IDs
        $existingTransfers = $this->stripeTransferRepository->findTransfersByInvoiceIds(array_keys($invoices));
        $type = StripeTransfer::TRANSFER_INVOICE;
        $transfersByInvoiceId = [];
        foreach ($invoices as $invoice) {
            $invoiceId = $invoice['invoice_id'];
            if (isset($existingTransfers[$invoiceId][$type])) {
                continue;
            }
            
            // Create new transfer
            if (!$this->isInvoiceInAnyCreatedTopup($invoiceId)) {
                $transfer = $this->stripeTransferFactory->createFromInvoiceTransfer($invoice, $type);
                $transfer->setStatus(StripeTransfer::TRANSFER_ON_HOLD);
                $transfer->setStatusReason("Invoice " . $invoiceId . " was not part in any created Topup");
                $this->stripeTransferRepository->persist($transfer);
                continue;
            }

            $transfer = $this->stripeTransferFactory->createFromInvoiceTransfer($invoice, $type);
            $this->stripeTransferRepository->persist($transfer);

            $transfersByInvoiceId[$invoiceId][$type] = $transfer;
        }

        // Save
        $this->stripeTransferRepository->flush();

        return $transfersByInvoiceId;
    }

    /**
     * @return array [ invoice_id => StripeTransfer[] ]
     */
    public function updateTransfersFromInvoices(array $existingTransfers, array $invoices)
    {
        $updated = [];
        foreach ($existingTransfers as $invoiceId => $transfers) {
            foreach ($transfers as $type => $transfer) {
                if (!isset($updated[$invoiceId])) {
                    $updated[$invoiceId] = [];
                }

                $updated[$invoiceId][$type] = $this->stripeTransferFactory->updateFromInvoice(
                    $transfer,
                    $invoices[(int) $invoiceId],
                    $type
                );

                if (!$this->isInvoiceInAnyCreatedTopup($invoiceId)) {
                    $updated[$invoiceId][$type]->setStatus(StripeTransfer::TRANSFER_ON_HOLD);
                    $updated[$invoiceId][$type]->setStatusReason("Invoice " . $invoiceId . " was not part in any created Topup");
                }
            }
        }

        // Save
        $this->stripeTransferRepository->flush();

        return $updated;
    }

    /**
     * @return array StripePayout[]
     */
    public function getRetriablePayouts(): array
    {
        return $this->stripePayoutRepository->findRetriablePayouts();
    }

    /**
     * @return array StripePayout[]
     */
    public function getPayoutsFromInvoices(array $invoices, MiraklClient $mclient): array
    {
        // Retrieve existing StripeTransfers with provided invoice IDs
        $existingPayouts = $this->stripePayoutRepository
            ->findPayoutsByInvoiceIds(array_keys($invoices));

        $payouts = [];
        foreach ($invoices as $invoice) {
            $invoiceId = (int) $invoice['invoice_id'];
            if (is_array($existingPayouts) && isset($existingPayouts[$invoiceId])) {
                $payout = $existingPayouts[$invoiceId];
                if (!$payout->isRetriable()) {
                    continue;
                }

                if (!$this->isInvoiceInAnyCreatedTopup($invoiceId)) {
                    $payout->setStatus(StripePayout::PAYOUT_ON_HOLD);
                    $payout->setStatusReason("Invoice " . $invoiceId . " was not part in any created Topup");
                    continue;
                }

                // Use existing payout
                $payout = $this->stripePayoutFactory->updateFromInvoice($payout, $invoice, $mclient);
            } else {
                if (!$this->isInvoiceInAnyCreatedTopup($invoiceId)) {
                    $payout = $this->stripePayoutFactory->createFromInvoice($invoice, $mclient);
                    $payout->setStatus(StripePayout::PAYOUT_ON_HOLD);
                    $payout->setStatusReason("Invoice " . $invoiceId . " was not part in any created Topup");
                    $this->stripePayoutRepository->persist($payout);
                    continue;
                }

                // Create new payout
                $payout = $this->stripePayoutFactory->createFromInvoice($invoice, $mclient);
                $this->stripePayoutRepository->persist($payout);
            }

            $payouts[] = $payout;
        }

        // Save
        $this->stripePayoutRepository->flush();

        return $payouts;
    }

    /**
     * @return array StripePayout[]
     */
    public function updatePayoutsFromInvoices(array $existingPayouts, array $invoices, MiraklClient $mclient)
    {
        $updated = [];
        foreach ($existingPayouts as $invoiceId => $payout) {
            $updated[$invoiceId] = $this->stripePayoutFactory->updateFromInvoice(
                $payout,
                $invoices[$invoiceId],
                $mclient
            );

            if (!$this->isInvoiceInAnyCreatedTopup($invoiceId)) {
                $updated[$invoiceId]->setStatus(StripePayout::PAYOUT_ON_HOLD);
                $updated[$invoiceId]->setStatusReason("Invoice " . $invoiceId . " was not part in any created Topup");
            }
        }

        // Save
        $this->stripePayoutRepository->flush();

        return $updated;
    }

    private function isInvoiceInLastTopup(int $invoiceId): bool
    {
        $topup = $this->stripeTopupRepository->findOneBy(
            ['status' => StripeTopup::TOPUP_CREATED],
            ['id' => 'DESC']
        );

        if (!$topup) {
            return false;
        }

        $invoiceIds = array_map(
            fn($item) => (int)$item['invoice_id'],
            $topup->getInvoiceIds()
        );

        return in_array($invoiceId, $invoiceIds);
    }

    private function isInvoiceInAnyCreatedTopup(int $invoiceId): bool
    {
        $topups = $this->stripeTopupRepository->findBy([
            'status' => StripeTopup::TOPUP_CREATED,
        ]);

        foreach ($topups as $topup) {
            $invoiceIds = array_map(
                static fn($item) => (int)$item['invoice_id'],
                $topup->getInvoiceIds()
            );

            if (in_array($invoiceId, $invoiceIds, true)) {
                return true;
            }
        }

        return false;
    }
}
