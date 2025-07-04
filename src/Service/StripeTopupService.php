<?php

namespace App\Service;

use App\Factory\StripeTopupFactory;
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
     * @param StripeTopupFactory $stripeTopupFactory
     * @param StripeTopupRepository $stripeTopupRepository
     */
    public function __construct(
        StripeTopupFactory $stripeTopupFactory,
        StripeTopupRepository $stripeTopupRepository,
    ) {
        $this->stripeTopupFactory = $stripeTopupFactory;
        $this->stripeTopupRepository = $stripeTopupRepository;
    }

    /**
     * @param array $invoices
     * @param MiraklClient $mclient
     * @return array
     */
    public function getTopupsFromInvoices(array $invoices, MiraklClient $mclient): array
    {
        // Retrieve existing topups based on invoices
        $topups = $this->stripeTopupFactory->createFromInvoices($invoices, $mclient);

        foreach ($topups as $topup) {
            $this->stripeTopupRepository->persist($topup);
            $this->stripeTopupRepository->flush();
        }

        return $topups;
    }
}
