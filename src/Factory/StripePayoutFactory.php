<?php

namespace App\Factory;

use App\Entity\AccountMapping;
use App\Entity\StripePayout;
use App\Exception\InvalidArgumentException;
use App\Repository\AccountMappingRepository;
use App\Service\MiraklClient;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

class StripePayoutFactory implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var AccountMappingRepository
     */
    private $accountMappingRepository;

    private $enablePaymentTaxSplit;

    public function __construct(
        AccountMappingRepository $accountMappingRepository,
        bool $enablePaymentTaxSplit
    ) {
        $this->accountMappingRepository = $accountMappingRepository;
        $this->enablePaymentTaxSplit = $enablePaymentTaxSplit;
    }

    public function createFromInvoice(array $invoice, MiraklClient $mclient): StripePayout
    {
        $payout = new StripePayout();
        $payout->setMiraklInvoiceId($invoice['invoice_id']);
        $payout->setMiraklCreatedDate(
            MiraklClient::getDatetimeFromString($invoice['date_created'])
        );

        return $this->updateFromInvoice($payout, $invoice, $mclient);
    }

    public function updateFromInvoice(StripePayout $payout, array $invoice, MiraklClient $mclient): StripePayout
    {
        // Payout already created
        if ($payout->getPayoutId()) {
            return $this->markPayoutAsCreated($payout);
        }

        // Amount and currency
        try {
            $payout->setAmount($this->getInvoiceAmount($invoice, $mclient));
            $payout->setCurrency(strtolower($invoice['currency_iso_code']));

            $shop_accountMapping = $this->getAccountMapping($invoice['shop_id'] ?? 0);
            $shopId = $shop_accountMapping->getMiraklShopId();

            if ($shop_accountMapping->getIgnored()) {
                return $this->ignorePayout($payout, $shopId, "Shop $shopId is ignored.");
            }

            if (!$shop_accountMapping->getPayoutEnabled()) {
                return $this->putPayoutOnKYCHold($payout, $shopId, "KYC incomplete for shop $shopId.");
            }
        } catch (InvalidArgumentException $e) {
            return $this->abortPayout($payout, $invoice['shop_id'], $e->getMessage());
        }

        // Save Stripe account corresponding with this shop
        try {
            $payout->setAccountMapping(
                $this->getAccountMapping($invoice['shop_id'] ?? 0)
            );
        } catch (InvalidArgumentException $e) {
            switch ($e->getCode()) {
                // Problem is final, let's abort
                case 10:
                    return $this->abortPayout($payout, $invoice['shop_id'], $e->getMessage());
                    // Problem is just temporary, let's put on hold
                case 20:
                    return $this->putPayoutOnHold($payout, $invoice['shop_id'], $e->getMessage());
            }
        }

        // All good
        return $payout->setStatus(StripePayout::PAYOUT_PENDING);
    }

    private function getAccountMapping(int $shopId): AccountMapping
    {
        if (!$shopId) {
            throw new InvalidArgumentException(StripePayout::PAYOUT_STATUS_REASON_NO_SHOP_ID, 10);
        }

        $mapping = $this->accountMappingRepository->findOneBy([
            'miraklShopId' => $shopId,
        ]);

        if (!$mapping) {
            throw new InvalidArgumentException(sprintf(StripePayout::PAYOUT_STATUS_REASON_SHOP_NOT_READY, $shopId), 20);
        }

        return $mapping;
    }

    private function getInvoiceAmount(array $invoice, MiraklClient $mclient): int
    {
        $amount = $invoice['summary']['amount_transferred'] ?? 0;
        if ($this->enablePaymentTaxSplit) {
            $transactions = $mclient->getTransactionsForInvoce($invoice['invoice_id']);
            $total_tax = $this->findTotalOrderTax($transactions);
            $amount = $amount - $total_tax;
        }

        $amount = gmp_intval((string) ($amount * 100));
        if ($amount <= 0) {
            throw new InvalidArgumentException(sprintf(StripePayout::PAYOUT_STATUS_REASON_INVALID_AMOUNT, $amount));
        }

        return $amount;
    }

    private function putPayoutOnKYCHold(StripePayout $payout, $shopId, string $reason): StripePayout
    {
        $this->logger->info(
            'Payout on KYC hold: '. $reason,
            [
                'invoiceId' => $payout->getMiraklInvoiceId(),
                'statusReason' => $reason,
                'miraklShopId' => $shopId
            ]
        );

        $payout->setStatusReason($reason);

        return $payout->setStatus(StripePayout::PAYOUT_ON_KYC_HOLD);
    }

    private function putPayoutOnHold(StripePayout $payout, $shopId, string $reason): StripePayout
    {
        $this->logger->info(
            'Payout on hold: '. $reason,
            [
                'invoiceId' => $payout->getMiraklInvoiceId(),
                'statusReason' => $reason,
                'miraklShopId' => $shopId
            ]
        );

        $payout->setStatusReason($reason);

        return $payout->setStatus(StripePayout::PAYOUT_ON_HOLD);
    }

    private function abortPayout(StripePayout $payout, int $shopId, string $reason): StripePayout
    {
        $this->logger->info(
            'Payout aborted: '. $reason,
            [
                'invoiceId' => $payout->getMiraklInvoiceId(),
                'statusReason' => $reason,
                'miraklShopId' => $shopId
            ]
        );

        $payout->setStatusReason($reason);

        return $payout->setStatus(StripePayout::PAYOUT_ABORTED);
    }

    private function ignorePayout(StripePayout $payout, int $shopId, string $reason): StripePayout
    {
        $this->logger->info(
            'Payout ignored: '. $reason,
            [
                'invoiceId' => $payout->getMiraklInvoiceId(),
                'statusReason' => $reason,
                'miraklShopId' => $shopId
            ]
        );

        $payout->setStatusReason($reason);

        return $payout->setStatus(StripePayout::PAYOUT_IGNORED);
    }

    private function markPayoutAsCreated(StripePayout $payout): StripePayout
    {
        $this->logger->info(
            'Payout created',
            [
                'invoiceId' => $payout->getMiraklInvoiceId(),
                'payoutId' => $payout->getPayoutId(),
                'statusReason' => $payout->getStatusReason(),
                'miraklShopId' => ($payout->getAccountMapping()) ? $payout->getAccountMapping()->getMiraklShopId() : 'No shop id available.',
                'accountMapping' => json_encode($payout->getAccountMapping() ?? [])
            ]
        );

        $payout->setStatusReason(null);

        return $payout->setStatus(StripePayout::PAYOUT_CREATED);
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
