<?php

namespace App\Handler;

use App\Exception\InvalidStripeAccountException;
use App\Message\AccountUpdateKYCMessage;
use App\Service\MiraklClient;
use App\Service\StripeClient;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Stripe\Account;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use Symfony\Component\Messenger\Handler\MessageSubscriberInterface;

class UpdateKYCStatusHandler implements MessageHandlerInterface, MessageSubscriberInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const CURRENTLY_DUE = 'currently_due';
    public const PENDING_VERIFICATION = 'pending_verification';
    public const DISABLED_REASON = 'disabled_reason';
    public const KYC_STATUS_APPROVED = 'APPROVED';
    public const KYC_STATUS_REFUSED = 'REFUSED';
    public const KYC_STATUS_PENDING_APPROVAL = 'PENDING_APPROVAL';
    public const KYC_STATUS_PENDING_SUBMISSION = 'PENDING_SUBMISSION';

    /**
     * @var MiraklClient
     */
    private $miraklClient;

    /**
     * @var StripeClient
     */
    private $stripeClient;

    /**
     * @param MiraklClient $miraklClient
     * @param StripeClient $stripeClient
     */
    public function __construct(
        MiraklClient $miraklClient,
        StripeClient $stripeClient
    ) {
        $this->miraklClient = $miraklClient;
        $this->stripeClient = $stripeClient;
    }

    /**
     * @param AccountUpdateKYCMessage $message
     * @return void
     * @throws InvalidStripeAccountException
     */
    public function __invoke(AccountUpdateKYCMessage $message): void
    {
        $messagePayload = $message->getContent()['payload'];
        $this->logger->info('Received Stripe `account.updated` webhook. Updating KYC status.', $messagePayload);

        $stripeAccount = $messagePayload['stripeAccount'];

        $this->miraklClient->updateShopKycStatus($messagePayload['miraklShopId'], $this->getKYCStatus($stripeAccount));
    }

    /**
     * @return iterable
     */
    public static function getHandledMessages(): iterable
    {
        yield AccountUpdateKYCMessage::class => [
            'from_transport' => 'update_kyc_status',
        ];
    }

    /**
     * @param Account $stripeAccount
     * @return string
     * @throws InvalidStripeAccountException
     */
    private function getKYCStatus(Account $stripeAccount): string
    {
        $requirements = $stripeAccount->requirements;

        if (isset($requirements[self::CURRENTLY_DUE]) && count((array) $requirements[self::CURRENTLY_DUE]) > 0) {
            return self::KYC_STATUS_PENDING_SUBMISSION;
        }

        if (isset($requirements[self::PENDING_VERIFICATION]) && count((array) $requirements[self::PENDING_VERIFICATION]) > 0) {
            return self::KYC_STATUS_PENDING_APPROVAL;
        }
        $disabledReason = isset($requirements[self::DISABLED_REASON]) ? ''.$requirements[self::DISABLED_REASON] : '';
        if (
            isset($requirements[self::DISABLED_REASON]) && '' !== $requirements[self::DISABLED_REASON]
            && 0 === strpos($disabledReason, 'rejected')
        ) {
            return self::KYC_STATUS_REFUSED;
        }

        if (isset($requirements[self::DISABLED_REASON]) && '' !== $requirements[self::DISABLED_REASON] && null !== $requirements[self::DISABLED_REASON]) {
            return self::KYC_STATUS_PENDING_APPROVAL;
        }

        if ($stripeAccount->payouts_enabled && $stripeAccount->charges_enabled) {
            return self::KYC_STATUS_APPROVED;
        }

        $this->logger->error(sprintf('Could not calculate KYC status for account %s', $stripeAccount->id), [
            'requirements' => $requirements,
        ]);

        throw new InvalidStripeAccountException();
    }
}
