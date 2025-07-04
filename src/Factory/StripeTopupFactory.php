<?php

namespace App\Factory;

use App\Entity\AccountMapping;
use App\Entity\StripeTopup;
use App\Exception\InvalidArgumentException;
use App\Repository\AccountMappingRepository;
use App\Service\MiraklClient;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

class StripeTopupFactory implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var AccountMappingRepository
     */
    private $accountMappingRepository;

    /**
     * @var bool
     */
    private $enablePaymentTaxSplit;

    /**
     * @param AccountMappingRepository $accountMappingRepository
     * @param bool $enablePaymentTaxSplit
     */
    public function __construct(
        AccountMappingRepository $accountMappingRepository,
        bool $enablePaymentTaxSplit
    ) {
        $this->accountMappingRepository = $accountMappingRepository;
        $this->enablePaymentTaxSplit = $enablePaymentTaxSplit;
    }

    public function createFromInvoices(array $invoices, MiraklClient $mclient): array
    {
        // Group invoices by currency
        $invoicesByCurrency = [];
        foreach ($invoices as $invoice) {
            $currency = $invoice['currency_iso_code'];
            $invoicesByCurrency[$currency][] = $invoice;
        }

        // Create a topup for each currency group
        $topups = [];
        foreach ($invoicesByCurrency as $currency => $currencyInvoices) {
            $topup = new StripeTopup();
            $topup->setMiraklCreatedDate(new \DateTime());
            $topup->setCurrency(strtolower($currency));
            $topups[] = $this->updateFromInvoices($topup, $currencyInvoices, $mclient);
        }

        return $topups;
    }

    public function updateFromInvoices(StripeTopup $topup, array $invoices, MiraklClient $mclient): StripeTopup
    {
        // Topup already created
        if ($topup->getTopupId()) {
            return $this->markTopupAsCreated($topup);
        }

        // Amount and currency
        try {
            $amount = 0;

            foreach ($invoices as $invoice) {
                $shop_accountMapping = $this->getAccountMapping($invoice['shop_id'] ?? 0);
                if ($shop_accountMapping->getIgnored()) {
                    continue;
                }
                $amount += $this->getInvoiceAmount($invoice, $mclient);
            }

            $topup->setAmount($amount);
        } catch (InvalidArgumentException $e) {
            return $this->abortTopup($topup, $e->getMessage());
        }

        // All good
        return $topup->setStatus(StripeTopup::TOPUP_PENDING);
    }


    private function getAccountMapping(int $shopId): AccountMapping
    {
        if (!$shopId) {
            throw new InvalidArgumentException(StripeTopup::TOPUP_STATUS_REASON_NO_SHOP_ID, 10);
        }

        $mapping = $this->accountMappingRepository->findOneBy([
            'miraklShopId' => $shopId,
        ]);

        if (!$mapping) {
            throw new InvalidArgumentException(sprintf(StripeTopup::TOPUP_STATUS_REASON_SHOP_NOT_READY, $shopId), 20);
        }

        if (!$mapping->getPayoutEnabled()) {
            throw new InvalidArgumentException(sprintf(StripeTopup::TOPUP_STATUS_REASON_SHOP_TOPUP_DISABLED, $shopId), 20);
        }

        return $mapping;
    }

    private function getInvoiceAmount(array $invoice, MiraklClient $mclient): int
    {
        $amount = $invoice['summary']['amount_transferred'] ?? 0;
        $transactions = $mclient->getTransactionsForInvoce($invoice['invoice_id']);
        if ($this->enablePaymentTaxSplit) {
            $total_tax = $this->findTotalOrderTax($transactions);
            $amount = $amount - $total_tax;
        }

        $amount = gmp_intval((string) ($amount * 100));
        if ($amount <= 0) {
            throw new InvalidArgumentException(sprintf(StripeTopup::TOPUP_STATUS_REASON_INVALID_AMOUNT, $amount));
        }

        return $amount;
    }

    private function putTopupOnHold(StripeTopup $topup, string $reason): StripeTopup
    {
        $this->logger->info(
            'Topup on hold: '. $reason,
            [
                'statusReason' => $reason,
            ]
        );

        $topup->setStatusReason($reason);

        return $topup->setStatus(StripeTopup::TOPUP_ON_HOLD);
    }

    private function abortTopup(StripeTopup $topup, string $reason): StripeTopup
    {
        $this->logger->info(
            'Topup aborted: '. $reason,
            [
                'statusReason' => $reason,
            ]
        );

        $topup->setStatusReason($reason);

        return $topup->setStatus(StripeTopup::TOPUP_ABORTED);
    }

    private function markTopupAsCreated(StripeTopup $topup): StripeTopup
    {
        $this->logger->info(
            'Topup created',
            [
                'topupId' => $topup->getTopupId(),
                'statusReason' => $topup->getStatusReason(),
            ]
        );

        $topup->setStatusReason(null);

        return $topup->setStatus(StripeTopup::TOPUP_CREATED);
    }

    private function findTotalOrderTax(array $transactions): float
    {
        $taxes = 0;
        foreach ($transactions as $trx) {
            if ('ORDER_AMOUNT_TAX' == $trx['type']) {
                $taxes += (float) $trx['amount'];
            }
        }

        return $taxes;
    }
}
