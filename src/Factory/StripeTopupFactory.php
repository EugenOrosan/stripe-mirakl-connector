<?php

namespace App\Factory;

use App\Entity\AccountMapping;
use App\Entity\StripeTopup;
use App\Entity\StripeTopupInvoiceData;
use App\Exception\InvalidArgumentException;
use App\Repository\AccountMappingRepository;
use App\Repository\StripeTopupInvoiceDataRepository;
use App\Repository\StripeTopupRepository;
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
     * @var StripeTopupInvoiceDataRepository
     */
    private $stripeTopupInvoiceDataRepository;

    /**
     * @var StripeTopupRepository
     */
    private $stripeTopupRepository;

    /**
     * @var bool
     */
    private $enablePaymentTaxSplit;

    /**
     * @param AccountMappingRepository $accountMappingRepository
     * @param StripeTopupInvoiceDataRepository $stripeTopupInvoiceDataRepository
     * @param StripeTopupRepository $stripeTopupRepository
     * @param bool $enablePaymentTaxSplit
     */
    public function __construct(
        AccountMappingRepository $accountMappingRepository,
        StripeTopupInvoiceDataRepository $stripeTopupInvoiceDataRepository,
        StripeTopupRepository $stripeTopupRepository,
        bool $enablePaymentTaxSplit
    ) {
        $this->accountMappingRepository = $accountMappingRepository;
        $this->stripeTopupInvoiceDataRepository = $stripeTopupInvoiceDataRepository;
        $this->stripeTopupRepository = $stripeTopupRepository;
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
        // Amount and currency
        try {
            $amount = 0;
            $this->logger->info('Calculating total amount from invoices, invoiceCount ' . count($invoices));

            $i = 0;
            $invoiceIds = [];
            $onHoldInvoices = [];

            foreach ($invoices as $invoice) {
                $this->logger->info('Start processing invoice: ' . $invoice['invoice_id'] . ', shopId: ' . ($invoice['shop_id'] ?? 0));
                try {
                    $shop_accountMapping = $this->getAccountMapping($invoice['shop_id'] ?? 0);
                }  catch (InvalidArgumentException $e) {
                    $reason = 'Skipping invoice due to account mapping issue: ' . $e->getMessage() . ', invoiceId: ' . ($invoice['invoice_id'] ?? 'N/A');
                    $this->logger->info($reason);
                    $onHoldInvoices[] = $this->createInvoiceDataEntity($invoice, 0, StripeTopupInvoiceData::INVOICE_TOPUP_ON_HOLD, $reason, $mclient);
                    continue;
                }

                if ($shop_accountMapping->getIgnored()) {
                    $reason = 'Shop is ignored, skipping invoice, shopId: ' . $shop_accountMapping->getMiraklShopId();
                    $this->logger->info($reason);
                    $onHoldInvoices[] = $this->createInvoiceDataEntity($invoice, 0, StripeTopupInvoiceData::INVOICE_TOPUP_ON_HOLD, $reason, $mclient);
                    continue;
                }

                if (!$shop_accountMapping->getPayoutEnabled()) {
                    $reason = 'Topup - Payout not enabled for shop, shopId: ' . $shop_accountMapping->getMiraklShopId() . ', continue to next invoice.';
                    $this->logger->error($reason);
                    $onHoldInvoices[] = $this->createInvoiceDataEntity($invoice, 0, StripeTopupInvoiceData::INVOICE_TOPUP_ON_HOLD, $reason, $mclient);
                    continue;
                }

                $this->logger->info(
                    'Processing invoice: ' . $invoice['invoice_id'] . ', shopId: ' . $shop_accountMapping->getMiraklShopId()
                );

                $invoiceAmount = $this->getInvoiceAmount($invoice, $mclient);
                $this->logger->info('Invoice amount calculated, invoiceId: ' . $invoice['invoice_id'] . ', amount: ' . $invoiceAmount);
                $amount += $invoiceAmount;

                $invoiceIds[$i] = [
                    'invoice_id' => $invoice['invoice_id'],
                    'amount' => $invoiceAmount,
                    'date_created_from_mirakl' => $invoice['date_created'] ?? null,
                ];

                $this->createInvoiceDataEntity($invoice, $amount, StripeTopupInvoiceData::INVOICE_TOPUP_PENDING, null, $mclient);

                $i++;
            }

            if (!empty($onHoldInvoices)) {
                $this->logger->info('Some invoices are put on hold, totalOnHoldInvoices: ' . count($onHoldInvoices));
                $this->createNewTopupOnHold($onHoldInvoices, $topup->getCurrency());
            }

            $this->stripeTopupInvoiceDataRepository->flush();

            $topup->setAmount($amount);
            $topup->setInvoiceIds($invoiceIds);

            if ($amount <= 0) {
                $this->logger->error('Topup amount is zero or negative, amount: ' . $amount);
                return $this->abortTopup($topup, 'Topup amount is zero or negative, amount: ' . $amount);
            }
        } catch (InvalidArgumentException $e) {
            $this->logger->error('Error during invoice processing, message: ' . $e->getMessage());
            return $this->abortTopup($topup, $e->getMessage());
        }

        // All good
        $this->logger->info('Topup completed successfully, topupAmount: ' . $amount);
        return $topup->setStatus(StripeTopup::TOPUP_PENDING);
    }

    private function createInvoiceDataEntity(array $invoice, $amount, $status, $statusReason, MiraklClient $mclient): StripeTopupInvoiceData
    {
        $stripeTopupInvoice = $this->stripeTopupInvoiceDataRepository->findOneBy([
            'invoiceNumber' => $invoice['invoice_id']
        ]);
        if (!$stripeTopupInvoice) {
            $this->logger->info('Creating new StripeTopupInvoiceData entity for invoiceId: ' . $invoice['invoice_id']);
            $stripeTopupInvoice = new StripeTopupInvoiceData();
            $stripeTopupInvoice->setCreatedAt(new \DateTimeImmutable());
            $stripeTopupInvoice->setTopupInternalId(0);
        }
        $this->logger->info('Updating StripeTopupInvoiceData entity for invoiceId: ' . $invoice['invoice_id']);
        $stripeTopupInvoice->setTopupStripeId(0);
        $stripeTopupInvoice->setInvoiceNumber($invoice['invoice_id']);
        $stripeTopupInvoice->setAmount($this->getInvoiceAmount($invoice, $mclient));
        $stripeTopupInvoice->setStatus($status);
        $stripeTopupInvoice->setStatusReason($statusReason ?? '');
        $stripeTopupInvoice->setUpdatedAt(new \DateTimeImmutable());
        $stripeTopupInvoice->setDateCreatedFromMirakl(new \DateTimeImmutable($invoice['date_created']));
        $this->stripeTopupInvoiceDataRepository->persist($stripeTopupInvoice);

        if ($status == StripeTopupInvoiceData::INVOICE_TOPUP_PENDING) {
            $this->logger->info('Invoice marked as pending for topup, invoiceId: ' . $invoice['invoice_id']);
            $this->logger->info('Checking if invoice was previously assigned to an on-hold topup, invoiceId: ' . $invoice['invoice_id']);
            $topupIdAssigned = $stripeTopupInvoice->getTopupInternalId();
            $topupAssigned = $this->stripeTopupRepository->findOneBy([
                'id' => $topupIdAssigned,
                'status' => StripeTopup::TOPUP_ON_HOLD
            ]);
            if ($topupAssigned) {
                $currentlyInvoiceIds = $topupAssigned->getInvoiceIds() ?? [];
                if ($currentlyInvoiceIds) {
                    foreach ($currentlyInvoiceIds as $key => $item) {
                        if ($item['invoice_id'] === $invoice['invoice_id']) {
                            unset($currentlyInvoiceIds[$key]);
                            $this->logger->info('Removing invoice from on-hold topup, invoiceId: ' . $invoice['invoice_id'] . ', topupId: ' . $topupAssigned->getId());
                        }
                    }
                    $currentlyInvoiceIds = array_values($currentlyInvoiceIds);
                    $topupAssigned->setInvoiceIds($currentlyInvoiceIds);
                    $this->logger->info('Updating on-hold topup after removing invoice, topupId: ' . $topupAssigned->getId() . ', new invoice items: ' . json_encode($currentlyInvoiceIds));
                    $this->stripeTopupRepository->persist($topupAssigned);
                    if (empty($currentlyInvoiceIds)) {
                        $this->logger->info('No more invoices left in on-hold topup, removing topup, topupId: ' . $topupAssigned->getId());
                        $this->stripeTopupRepository->remove($topupAssigned);
                    }
                    $this->stripeTopupRepository->flush();
                }
            }
        }

        return $stripeTopupInvoice;
    }

    private function createNewTopupOnHold($onHoldInvoices, string $currency): void
    {
        $invoicesData = [];
        foreach ($onHoldInvoices as $onHoldInvoiceData) {
            if ($onHoldInvoiceData->getTopupInternalId()) {
                $this->logger->info('Invoice already assigned to an on-hold topup (ID: ' . $onHoldInvoiceData->getTopupInternalId() . '), skipping invoiceId: ' . $onHoldInvoiceData->getInvoiceNumber());
                continue;
            }
            $invoicesData[] = [
                'invoice_id' => $onHoldInvoiceData->getInvoiceNumber(),
                'amount' => $onHoldInvoiceData->getAmount(),
                'date_created_from_mirakl' => $onHoldInvoiceData->getDateCreatedFromMirakl()->format('Y-m-d H:i:s')
            ];
        }
        if ($invoicesData) {
            $topup = new StripeTopup();
            $topup->setMiraklCreatedDate(new \DateTime());
            $topup->setCurrency(strtolower($currency));
            $topup->setAmount(0);
            $topup->setStatus(StripeTopup::TOPUP_ON_HOLD);
            $topup->setStatusReason('On Hold Invoices');
            $topup->setInvoiceIds($invoicesData);
            $this->stripeTopupRepository->persist($topup);
            $this->stripeTopupRepository->flush();
            foreach ($onHoldInvoices as $onHoldInvoiceData) {
                $onHoldInvoiceData->setTopupInternalId($topup->getId());
                $this->stripeTopupInvoiceDataRepository->persist($onHoldInvoiceData);
            }
            $this->stripeTopupInvoiceDataRepository->flush();
        }
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
        if ($this->enablePaymentTaxSplit) {
            $transactions = $mclient->getTransactionsForInvoce($invoice['invoice_id']);
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
        $this->logger->info('Topup on hold: '. $reason);

        $topup->setStatusReason($reason);

        return $topup->setStatus(StripeTopup::TOPUP_ON_HOLD);
    }

    private function abortTopup(StripeTopup $topup, string $reason): StripeTopup
    {
        $this->logger->info('Topup aborted: '. $reason);

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
