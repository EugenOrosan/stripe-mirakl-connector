<?php

namespace App\Service;

use App\Entity\StripeDispute;
use App\Entity\StripeDisputeAdjustment;
use App\Entity\StripeTransfer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Stripe\Event;
use Symfony\Component\Intl\Currencies;

class DisputeRecoveryService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    /**
     * @var bool
     */
    private $disputeRecoveryEnabled;

    /**
     * @var bool
     */
    private $disputeRecoveryIncludePreviousDisputes;

    /**
     * @param EntityManagerInterface $entityManager
     * @param bool $disputeRecoveryEnabled
     * @param bool $disputeRecoveryIncludePreviousDisputes
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        bool $disputeRecoveryEnabled,
        bool $disputeRecoveryIncludePreviousDisputes
    ) {
        $this->entityManager = $entityManager;
        $this->disputeRecoveryEnabled = $disputeRecoveryEnabled;
        $this->disputeRecoveryIncludePreviousDisputes = $disputeRecoveryIncludePreviousDisputes;
    }

    public function recordEvent(Event $event): void
    {
        $object = $event->data['object'];
        if (!($object instanceof \Stripe\Dispute)) {
            throw new \UnexpectedValueException('Dispute webhook must contain a complete dispute object.');
        }
        $payload = $object->toArray();
        $id = $payload['id'] ?? null;
        $chargeId = $payload['charge'] ?? null;
        if (is_array($chargeId)) {
            $chargeId = $chargeId['id'] ?? null;
        }
        $currency = $payload['currency'] ?? null;
        $status = $payload['status'] ?? null;
        if (!is_string($id) || '' === $id || !is_string($chargeId) || '' === $chargeId ||
            !is_string($currency) || !preg_match('/^[a-z]{3}$/D', $currency) ||
            !is_string($status) || '' === $status ||
            !is_int($event->created) || !isset($payload['balance_transactions']) ||
            !is_array($payload['balance_transactions'])) {
            throw new \UnexpectedValueException('Dispute webhook is missing valid IDs, currency, creation time or balance_transactions.');
        }

        $closed = in_array($status, ['lost', 'won', 'warning_closed'], true);
        if ('charge.dispute.closed' === $event->type && !$closed) {
            throw new \UnexpectedValueException('A closed dispute event must contain a terminal status.');
        }

        $transactions = [];
        $settlementCurrency = null;
        foreach ($payload['balance_transactions'] as $transaction) {
            $transactionId = $transaction['id'] ?? null;
            $net = $transaction['net'] ?? null;
            $transactionCurrency = $transaction['currency'] ?? null;
            if (!is_string($transactionId) || '' === $transactionId || !is_int($net) ||
                !is_string($transactionCurrency) || !preg_match('/^[a-z]{3}$/D', $transactionCurrency)) {
                throw new \UnexpectedValueException('Dispute balance transactions must contain an ID, integer net and currency.');
            }
            if (null !== $settlementCurrency && $settlementCurrency !== $transactionCurrency) {
                throw new \UnexpectedValueException('Cannot add dispute balance transactions in different currencies.');
            }
            $settlementCurrency = $transactionCurrency;
            $entry = ['net' => $net, 'currency' => $transactionCurrency];
            if (isset($transactions[$transactionId]) && $transactions[$transactionId] !== $entry) {
                throw new \UnexpectedValueException('Duplicate balance transaction ID has conflicting values.');
            }
            $transactions[$transactionId] = $entry;
        }

        $dispute = $this->entityManager->find(StripeDispute::class, $id);
        if (null === $dispute) {
            $dispute = new StripeDispute($id, $chargeId, $settlementCurrency ?? $currency);
            $this->entityManager->persist($dispute);
        } elseif ($dispute->getChargeId() !== $chargeId) {
            throw new \UnexpectedValueException('A dispute cannot change its charge.');
        }
        $dispute->synchronize(
            $transactions,
            $settlementCurrency ?? $dispute->getCurrency(),
            $closed,
            $event->created,
            $this->disputeRecoveryEnabled
        );
        $this->logger->info('Dispute cost synchronized from webhook.', [
            'disputeId' => $id,
            'eventId' => $event->id,
            'currency' => $dispute->getCurrency(),
            'cost' => $dispute->getCost(),
            'alreadyRecovered' => $dispute->getAlreadyRecovered(),
            'amountUnit' => 'minor currency units (USD: cents)',
            'costFormatted' => $this->formatLogAmount($dispute->getCost(), $dispute->getCurrency()),
            'outstandingFormatted' => $this->formatLogAmount($dispute->getOutstanding(), $dispute->getCurrency()),
            'closed' => $closed,
            'closedWhileRecoveryEnabled' => $dispute->isClosedWhileRecoveryEnabled(),
        ]);
        $this->entityManager->flush();
    }

    /**
     * Returns the amount and metadata to send for a T&C transfer, or null when it must not be sent now.
     * The first result is saved on the transfer, so a retry sends exactly the same request.
     */
    public function prepareCommissionTaxTransfer(StripeTransfer $transfer, array $metadata): ?array
    {
        if ($transfer->isCommissionTaxCompleted()) {
            return null;
        }
        if (null !== $transfer->getCommissionTaxRequest()) {
            $this->logger->info('Dispute recovery: retry uses the saved commission and tax request; no new calculation.', [
                'transferId' => $transfer->getId(),
                'invoiceId' => $transfer->getMiraklId(),
                'currency' => $transfer->getCurrency(),
                'savedRequest' => $transfer->getCommissionTaxRequest(),
                'savedRequestAmountUnit' => 'minor currency units (USD: cents)',
            ]);
            return $transfer->getCommissionTaxRequest();
        }
        if (!in_array($transfer->getStatus(), [
            StripeTransfer::TRANSFER_PENDING,
            StripeTransfer::TRANSFER_COMMISSION_TAX_FAILED,
            StripeTransfer::TRANSFER_COMMISSION_TAX_ON_HOLD,
        ], true)) {
            $this->logger->warning('Ignoring non-dispatchable commission tax transfer.', [
                'transferId' => $transfer->getId(),
                'status' => $transfer->getStatus(),
            ]);

            return null;
        }

        $originalAmount = $transfer->getAmount();
        $currency = $transfer->getCurrency();
        if (StripeTransfer::TRANSFER_COMMISSION_TAX !== $transfer->getType() ||
            null === $originalAmount || $originalAmount < 0 ||
            null === $currency || !preg_match('/^[a-z]{3}$/D', $currency)) {
            throw new \UnexpectedValueException('Cannot prepare an invalid commission tax transfer.');
        }

        $amount = $originalAmount;
        $disputesAdjustment = [];
        $disputeDetails = [];
        $totalCost = 0;
        $totalRecovered = 0;
        $totalOutstanding = 0;
        if (!$transfer->isReversed() && $originalAmount > 0) {
            if ($this->disputeRecoveryEnabled) {
                $blocker = $this->entityManager->createQueryBuilder()
                    ->select('t.id')
                    ->from(StripeDisputeAdjustment::class, 'a')
                    ->innerJoin('a.transfer', 't')
                    ->where('t.currency = :currency')
                    ->andWhere('t.status NOT IN (:completed)')
                    ->setParameter('currency', $currency)
                    ->setParameter('completed', [StripeTransfer::TRANSFER_CREATED, StripeTransfer::TRANSFER_COMMISSION_TAX_COVERED_BY_DISPUTES])
                    ->setMaxResults(1)
                    ->getQuery()
                    ->getOneOrNullResult();
                if (null !== $blocker) {
                    $reason = sprintf('Waiting for prepared dispute adjustment on T&C transfer %s.', $blocker['id']);
                    $transfer->setStatus(StripeTransfer::TRANSFER_COMMISSION_TAX_ON_HOLD)->setStatusReason($reason);
                    $this->logger->warning($reason, ['transferId' => $transfer->getId()]);
                    $this->entityManager->flush();

                    return null;
                }
            }

            $criteria = [
                'currency' => $currency,
                'closed' => true,
                'fullyRecovered' => false,
            ];
            // Simulation includes disputes closed while recovery was disabled.
            if ($this->disputeRecoveryEnabled && !$this->disputeRecoveryIncludePreviousDisputes) {
                $criteria['closedWhileRecoveryEnabled'] = true;
            }
            $disputes = $this->entityManager->getRepository(StripeDispute::class)->findBy(
                $criteria,
                ['lastEventCreated' => 'ASC', 'id' => 'ASC']
            );
            // Credits increase the capacity available to absorb other dispute losses.
            foreach ([false, true] as $losses) {
                foreach ($disputes as $dispute) {
                    $outstanding = $dispute->getOutstanding();
                    if (!$losses) {
                        $totalCost += $dispute->getCost();
                        $totalRecovered += $dispute->getAlreadyRecovered();
                        $totalOutstanding += $outstanding;
                        $disputeDetails[$dispute->getId()] = [
                            'initialCost' => $this->formatLogAmount($dispute->getCost(), $currency),
                            'alreadyRecoveredInDatabase' => $this->formatLogAmount($dispute->getAlreadyRecovered(), $currency),
                            'outstandingBeforeThisTransfer' => $this->formatLogAmount($outstanding, $currency),
                            'deductionForThisTransfer' => $this->formatLogAmount(0, $currency),
                            'outstandingAfterProposedDeduction' => $this->formatLogAmount($outstanding, $currency),
                            'closedWhileRecoveryEnabled' => $dispute->isClosedWhileRecoveryEnabled(),
                            'eligibleIfRecoveryEnabledWithCurrentIncludePreviousFlag' => $this->disputeRecoveryIncludePreviousDisputes || $dispute->isClosedWhileRecoveryEnabled(),
                        ];
                    }
                    if (($outstanding > 0) !== $losses || 0 === $outstanding) {
                        continue;
                    }
                    $applied = $losses ? min($amount, $outstanding) : $outstanding;
                    if (0 === $applied) {
                        continue;
                    }
                    $amount -= $applied;
                    $disputesAdjustment[$dispute->getId()] = $applied;
                    $disputeDetails[$dispute->getId()]['deductionForThisTransfer'] = $this->formatLogAmount($applied, $currency);
                    $disputeDetails[$dispute->getId()]['outstandingAfterProposedDeduction'] = $this->formatLogAmount($outstanding - $applied, $currency);
                    if ($this->disputeRecoveryEnabled) {
                        $this->entityManager->persist(new StripeDisputeAdjustment($dispute, $transfer, $applied));
                    }
                }
            }
        }

        $this->logger->info(
            $this->disputeRecoveryEnabled
                ? 'Dispute recovery: commission and tax deduction prepared, not yet confirmed.'
                : 'Dispute recovery: SIMULATION ONLY; the original commission and tax amount will be sent.',
            [
                'transferId' => $transfer->getId(),
                'invoiceId' => $transfer->getMiraklId(),
                'shopId' => $metadata['miraklShopId'] ?? null,
                'currency' => $currency,
                'recoveryEnabled' => $this->disputeRecoveryEnabled,
                'includePreviousDisputes' => $this->disputeRecoveryIncludePreviousDisputes,
                'selection' => $this->disputeRecoveryEnabled && !$this->disputeRecoveryIncludePreviousDisputes
                    ? 'Closed, unrecovered disputes in this currency that closed while recovery was enabled.'
                    : ($this->disputeRecoveryEnabled
                        ? 'All closed, unrecovered disputes in this currency.'
                        : 'All closed, unrecovered disputes in this currency; simulation ignores the include-previous flag.'),
                'calculation' => $transfer->isReversed() ? 'Skipped: reversed transfer.'
                    : (0 === $originalAmount ? 'Skipped: original amount is zero.'
                        : ([] === $disputeDetails ? 'No eligible disputes found.' : 'Calculated.')),
                'commissionAndTaxBeforeDeduction' => $this->formatLogAmount($originalAmount, $currency),
                'eligibleDisputesInitialCost' => $this->formatLogAmount($totalCost, $currency),
                'eligibleDisputesAlreadyRecoveredInDatabase' => $this->formatLogAmount($totalRecovered, $currency),
                'eligibleDisputesOutstandingBeforeThisTransfer' => $this->formatLogAmount($totalOutstanding, $currency),
                'proposedDeduction' => $this->formatLogAmount($originalAmount - $amount, $currency),
                'commissionAndTaxAfterProposedDeduction' => $this->formatLogAmount($amount, $currency),
                'eligibleDisputesOutstandingAfterProposedDeduction' => $this->formatLogAmount($totalOutstanding - ($originalAmount - $amount), $currency),
                'amountToSendToStripe' => $this->formatLogAmount($this->disputeRecoveryEnabled ? $amount : $originalAmount, $currency),
                'stripeTransferRequired' => !($this->disputeRecoveryEnabled && 0 === $amount && $originalAmount > 0),
                'disputes' => $disputeDetails,
                'databaseEffect' => $this->disputeRecoveryEnabled
                    ? 'Allocations are saved; disputes are marked recovered only on completion.'
                    : 'Only the simulation is saved on the transfer. No dispute recovery or adjustment is saved.',
                'simulationScope' => 'Independent calculation for this transfer, using current database balances; not a cumulative simulation across transfers.',
            ]
        );

        if (!$this->disputeRecoveryEnabled) {
            $request = [
                'original_amount' => $originalAmount,
                'amount' => $originalAmount,
                'metadata' => $metadata,
            ];
            if ([] !== $disputesAdjustment) {
                $request['simulated_amount'] = $amount;
                $request['simulated_disputes'] = $disputesAdjustment;
            }
        } else {
            if ([] !== $disputesAdjustment) {
                $metadata['commission_tax_original_amount'] = (string) $originalAmount;
                $metadata['disputes_adjustment'] = (string) ($originalAmount - $amount);
                $metadata['disputes_batch'] = 'tc-transfer-' . $transfer->getId();
                $list = implode(',', array_keys($disputesAdjustment));
                if (strlen($list) <= 500) {
                    $metadata['disputes_ids'] = $list;
                }
            }
            $request = [
                'original_amount' => $originalAmount,
                'amount' => $amount,
                'metadata' => $metadata,
            ];
        }
        $transfer->prepareCommissionTaxRequest($request);
        $transfer->setStatus(StripeTransfer::TRANSFER_PENDING)->setStatusReason(null);
        $this->entityManager->flush();

        return $request;
    }

    /**
     * Marks the dispute allocations as recovered once Stripe accepted the transfer,
     * or when disputes covered the whole T&C amount and nothing was sent ($stripeId null).
     */
    public function completeCommissionTaxTransfer(StripeTransfer $transfer, ?string $stripeId): void
    {
        if ($transfer->isCommissionTaxCompleted()) {
            return;
        }
        $adjustments = $this->entityManager->getRepository(StripeDisputeAdjustment::class)->findBy(['transfer' => $transfer]);
        $recoveredAmount = 0;
        foreach ($adjustments as $adjustment) {
            $dispute = $adjustment->getDispute();
            $dispute->applyRecovery($adjustment->getAmount());
            $recoveredAmount += $adjustment->getAmount();
        }
        $transfer->setTransferId($stripeId);
        $transfer->setStatus(null === $stripeId ? StripeTransfer::TRANSFER_COMMISSION_TAX_COVERED_BY_DISPUTES : StripeTransfer::TRANSFER_CREATED);
        $transfer->setStatusReason(null);
        $this->logger->info('Commission tax transfer and dispute adjustments completed.', [
            'transferId' => $transfer->getId(),
            'invoiceId' => $transfer->getMiraklId(),
            'stripeTransferId' => $stripeId,
            'amount' => $transfer->getAmount(),
            'amountUnit' => 'minor currency units (USD: cents)',
            'actualAmountSentToStripe' => $this->formatLogAmount(null === $stripeId ? 0 : $transfer->getAmount(), $transfer->getCurrency()),
            'disputeAmountActuallyRecovered' => $this->formatLogAmount($recoveredAmount, $transfer->getCurrency()),
            'adjustments' => count($adjustments),
        ]);
        $this->entityManager->flush();
    }

    private function formatLogAmount(int $amount, string $currency): string
    {
        $code = strtoupper($currency);
        if (!Currencies::exists($code)) {
            return sprintf('%d %s minor units', $amount, $code);
        }
        $digits = in_array($code, ['ISK', 'UGX'], true) ? 2 : Currencies::getFractionDigits($code);

        return number_format($amount / (10 ** $digits), $digits, '.', '') . ' ' . $code;
    }
}
