<?php

namespace App\Service;

use App\Entity\StripeTopup;
use App\Entity\StripeTopupInvoiceData;
use App\Factory\StripeTopupFactory;
use App\Repository\StripeTopupInvoiceDataRepository;
use App\Repository\StripeTopupRepository;

class StripeTopupService
{
    /**
     * @var StripeTopupFactory
     */
    private $stripeTopupFactory;

    /**
     * @var StripeTopupRepository
     */
    private $stripeTopupRepository;

    /**
     * @var StripeTopupInvoiceDataRepository
     */
    private $stripeTopupInvoiceDataRepository;

    /**
     * @param StripeTopupFactory $stripeTopupFactory
     * @param StripeTopupRepository $stripeTopupRepository
     * @param StripeTopupInvoiceDataRepository $stripeTopupInvoiceDataRepository
     */
    public function __construct(
        StripeTopupFactory $stripeTopupFactory,
        StripeTopupRepository $stripeTopupRepository,
        StripeTopupInvoiceDataRepository $stripeTopupInvoiceDataRepository
    ) {
        $this->stripeTopupFactory = $stripeTopupFactory;
        $this->stripeTopupRepository = $stripeTopupRepository;
        $this->stripeTopupInvoiceDataRepository = $stripeTopupInvoiceDataRepository;
    }

    /**
     * @param array $invoices
     * @param MiraklClient $mclient
     * @return array
     */
    public function getTopupsFromInvoices(array $invoices, MiraklClient $mclient): array
    {
        // Filter out invoices that already have a completed topup
        $filteredInvoices = [];
        foreach ($invoices as $invoice) {
            $existingInvoice = $this->stripeTopupInvoiceDataRepository->findOneBy([
                'invoiceNumber' => $invoice['invoice_id'],
                'status' => StripeTopupInvoiceData::INVOICE_TOPUP_COMPLETED
            ]);

            if (!$existingInvoice) {
                // Only process invoices that DO NOT have a completed topup
                $filteredInvoices[] = $invoice;
            }
        }

        // Retrieve existing topups based on invoices
        $topups = $this->stripeTopupFactory->createFromInvoices($filteredInvoices, $mclient);

        foreach ($topups as $topup) {
            $this->stripeTopupRepository->persist($topup);
        }
        $this->stripeTopupRepository->flush();

        return $topups;
    }

    public function getTopupsOnHold(): array
    {
        return $this->stripeTopupRepository->findBy([
            'status' => StripeTopup::TOPUP_ON_HOLD
        ]);
    }

    public function getInvoicesOnHold(): array
    {
        return $this->stripeTopupInvoiceDataRepository->findBy([
            'status' => StripeTopupInvoiceData::INVOICE_TOPUP_ON_HOLD
        ]);
    }

    public function createTopupsFromOnHoldInvoices(array $invoices, MiraklClient $mclient): array
    {
        $topups = $this->stripeTopupFactory->createFromInvoices($invoices, $mclient);

        foreach ($topups as $topup) {
            $this->stripeTopupRepository->persist($topup);
        }
        $this->stripeTopupRepository->flush();

        return $topups;
    }
}
