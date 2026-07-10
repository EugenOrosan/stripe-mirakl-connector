<?php

namespace App\Handler;

use App\Entity\AccountMapping;
use App\Entity\StripePayout;
use App\Message\ProcessPayoutMessage;
use App\Repository\StripePayoutRepository;
use App\Service\StripeClient;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

class ProcessPayoutHandler implements MessageHandlerInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var StripeClient
     */
    private $stripeClient;

    /**
     * @var StripePayoutRepository
     */
    private $stripePayoutRepository;

    public function __construct(
        StripeClient $stripeClient,
        StripePayoutRepository $stripePayoutRepository
    ) {
        $this->stripeClient = $stripeClient;
        $this->stripePayoutRepository = $stripePayoutRepository;
    }

    public function __invoke(ProcessPayoutMessage $message): void
    {
        $payout = $this->stripePayoutRepository->findOneBy([
            'id' => $message->getStripePayoutId(),
        ]);
        assert(null !== $payout && null !== $payout->getAccountMapping());
        assert(StripePayout::PAYOUT_CREATED !== $payout->getStatus());

        $accountMapping = $payout->getAccountMapping();

        if (!$this->verifyAvailableBalance($payout, $accountMapping)) {
            // Verification determined the payout cannot be executed and set it with PAYOUT_ABORTED status
            $this->stripePayoutRepository->flush();

            return;
        }

        try {
            $response = $this->stripeClient->createPayout(
                (string) $payout->getCurrency(),
                (int) $payout->getAmount(),
                $accountMapping->getStripeAccountId(),
                [
                    'miraklShopId' => $accountMapping->getMiraklShopId(),
                    'invoiceId' => $payout->getMiraklInvoiceId(),
                ]
            );

            $payout->setPayoutId($response->id);
            $payout->setStatus(StripePayout::PAYOUT_CREATED);
            $payout->setStatusReason(null);
        } catch (ApiErrorException $e) {
            $this->logger->error(
                sprintf('Could not create Stripe Payout: %s.', $e->getMessage()),
                [
                    'miraklShopId' => $accountMapping->getMiraklShopId(),
                    'stripeAccountId' => $accountMapping->getStripeAccountId(),
                    'stripePayoutId' => $payout->getMiraklInvoiceId(),
                    'stripeErrorCode' => $e->getStripeCode(),
                    'file' => $e->getFile() ??  'No file available.',
                    'line' => $e->getLine() ?? 'No line available.',
                    'trace' => $e->getTraceAsString() ?? 'No trace available.',
                ]
            );

            $payout->setStatus(StripePayout::PAYOUT_FAILED);
            $payout->setStatusReason(substr($e->getMessage(), 0, 1024));
        }

        $this->stripePayoutRepository->flush();
    }

    /**
     * Verifies the payout amount/currency against the seller's current available
     * Stripe balance before sending the payout.
     *
     * If the balance differs, the payout is updated with the available amount/currency,
     * while the original values are kept for reporting. If the available balance is
     * zero or negative, the payout is aborted permanently and not retried.
     *
     * Returns false when the payout must not be executed (balance <= 0).
     */
    private function verifyAvailableBalance(StripePayout $payout, AccountMapping $accountMapping): bool
    {
        $logContext = [
            'payoutId' => $payout->getId(),
            'invoiceId' => $payout->getMiraklInvoiceId(),
            'miraklShopId' => $accountMapping->getMiraklShopId(),
        ];

        //Stripe balance check
        try {
            $balance = $this->stripeClient->retrieveBalance($accountMapping->getStripeAccountId());
        } catch (ApiErrorException $e) {
            // Don't block payout creation if the verification fails
            $this->logger->info(
                'Could not verify Stripe available balance: '.$e->getMessage(),
                $logContext
            );

            return true;
        }

        $this->logger->info(
            'Balance verification: Stripe balance retrieved',
            $logContext + ['balance' => json_encode($balance)]
        );

        $available = $balance->available ?? [];
        if (empty($available)) {
            $this->logger->info('Balance verification: no available balances returned, payout left untouched', $logContext);

            return true;
        }

        // Use the available balance that matches the payout currency, if one exists
        $matching = null;
        foreach ($available as $entry) {
            if (strtolower($entry->currency) === $payout->getCurrency()) {
                $matching = $entry;
                break;
            }
        }

        // No available balance in the payout currency: fall back to the first available entry as the seller's real payout currency
        $verified = $matching ?? $available[0];
        $balanceCurrency = strtolower($verified->currency);
        $balanceAmount = (int) $verified->amount;

        if ($matching) {
            $this->logger->info(
                sprintf(
                    'Balance verification: available balance found in payout currency (%d %s available, payout amount %d %s)',
                    $balanceAmount,
                    $balanceCurrency,
                    $payout->getAmount(),
                    $payout->getCurrency()
                ),
                $logContext
            );
        } else {
            $this->logger->info(
                sprintf(
                    'Balance verification: no available balance in payout currency (%s), falling back to first available entry (%d %s available)',
                    $payout->getCurrency(),
                    $balanceAmount,
                    $balanceCurrency
                ),
                $logContext
            );
        }

        if ($balanceAmount <= 0) {
            $reason = sprintf(
                StripePayout::PAYOUT_STATUS_REASON_NO_AVAILABLE_BALANCE,
                $balanceAmount,
                $balanceCurrency,
                $payout->getAmount(),
                $payout->getCurrency()
            );

            $this->logger->info('Payout aborted: ' . $reason, $logContext);

            $payout->setStatus(StripePayout::PAYOUT_ABORTED);
            $payout->setStatusReason(substr($reason, 0, 1024));

            return false;
        }

        // Currency matches and the available balance equals the payout amount:
        // nothing to correct
        if ($matching && $balanceAmount === (int) $payout->getAmount()) {
            $this->logger->info(
                sprintf(
                    'Balance verification: available balance matches payout amount exactly (%d %s), payout left untouched',
                    $balanceAmount,
                    $balanceCurrency
                ),
                $logContext
            );

            return true;
        }

        // Any difference (amount when currencies match, or a different currency
        // altogether): book the available balance amount and currency on the payout
        $this->logger->info(
            'Balance verification: payout amount/currency booked from available balance',
            $logContext + [
                'payoutAmount' => $payout->getAmount(),
                'payoutCurrency' => $payout->getCurrency(),
                'balanceAmount' => $balanceAmount,
                'balanceCurrency' => $balanceCurrency,
            ]
        );

        $payout->setOriginalAmount($payout->getAmount());
        $payout->setOriginalCurrency($payout->getCurrency());

        $payout->setAmount($balanceAmount);
        $payout->setCurrency($balanceCurrency);

        return true;
    }
}
