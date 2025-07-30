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
            $this->logger->info('Calculating total amount from invoices, invoiceCount ' . count($invoices));

            foreach ($invoices as $invoice) {
                $this->logger->info('Start processing invoice: ' . $invoice['invoice_id'] . ', shopId: ' . ($invoice['shop_id'] ?? 0));
                $shop_accountMapping = $this->getAccountMapping($invoice['shop_id'] ?? 0);

                if ($shop_accountMapping->getIgnored()) {
                    $this->logger->info('Shop is ignored, skipping invoice, shopId: ' . $shop_accountMapping->getMiraklShopId());
                    continue;
                }

                if (!$shop_accountMapping->getPayoutEnabled()) {
                    $this->logger->error('Topup - Payout not enabled for shop, shopId: ' . $shop_accountMapping->getMiraklShopId() . ', continue to next invoice.');
                    continue;
                }

                $this->logger->info(
                    'Processing invoice: ' . $invoice['invoice_id'] . ', shopId: ' . $shop_accountMapping->getMiraklShopId() . ', ignored: ' . $shop_accountMapping->getIgnored()
                );

                $invoiceAmount = $this->getInvoiceAmount($invoice, $mclient);
                $this->logger->info('Invoice amount calculated, invoiceId: ' . $invoice['invoice_id'] . ', amount: ' . $invoiceAmount);
                $amount += $invoiceAmount;
            }

            $topup->setAmount($amount);
        } catch (InvalidArgumentException $e) {
            $this->logger->error('Error during invoice processing, message: ' . $e->getMessage());
            return $this->abortTopup($topup, $e->getMessage());
        }

        // All good
        $this->logger->info('Topup completed successfully, topupAmount: ' . $amount);
        return $topup->setStatus(StripeTopup::TOPUP_PENDING);
    }

    private function getAccountMapping(int $shopId): AccountMapping
    {
        if (!$shopId) {
            $this->logger->error('Topup - No shop ID provided, shopId ' . $shopId);
            throw new InvalidArgumentException(StripeTopup::TOPUP_STATUS_REASON_NO_SHOP_ID, 10);
        }

        $mapping = $this->accountMappingRepository->findOneBy([
            'miraklShopId' => $shopId,
        ]);

        if (!$mapping) {
            $this->logger->error('Topup - Shop not ready, no mapping found, shopId ' . $shopId);
            throw new InvalidArgumentException(sprintf(StripeTopup::TOPUP_STATUS_REASON_SHOP_NOT_READY, $shopId), 20);
        }

        if (!$mapping->getPayoutEnabled()) {
            $this->logger->error('Topup - Payout not enabled for shop, shopId ' . $shopId);
            //throw new InvalidArgumentException(sprintf(StripeTopup::TOPUP_STATUS_REASON_SHOP_TOPUP_DISABLED, $shopId), 20);
        }

        $this->logger->info('Topup - Account mapping retrieved successfully, shopId: ' . $shopId);

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
