<?php

namespace App\Handler;

use App\Exception\InvalidStripeAccountException;
use App\Message\AccountUpdateKYCMessage;
use App\Repository\AccountMappingRepository;
use App\Service\MiraklClient;
use App\Service\SellerOnboardingService;
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
     * @var AccountMappingRepository
     */
    private $accountMappingRepository;

    /**
     * @var SellerOnboardingService
     */
    private $sellerOnboardingService;

    /**
     * @param MiraklClient $miraklClient
     * @param StripeClient $stripeClient
     * @param AccountMappingRepository $accountMappingRepository
     * @param SellerOnboardingService $sellerOnboardingService
     */
    public function __construct(
        MiraklClient $miraklClient,
        StripeClient $stripeClient,
        AccountMappingRepository $accountMappingRepository,
        SellerOnboardingService $sellerOnboardingService,
    ) {
        $this->miraklClient = $miraklClient;
        $this->stripeClient = $stripeClient;
        $this->accountMappingRepository = $accountMappingRepository;
        $this->sellerOnboardingService = $sellerOnboardingService;
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

        if ($stripeAccount->details_submitted) {
            $this->logger->info('KYC update status - Shop ID ' . $messagePayload['miraklShopId'] . ' has submitted KYC details. Adding login link to shop.');
            $accountMapping = $this->accountMappingRepository->findOneByStripeAccountId($stripeAccount->id);
            $this->sellerOnboardingService->addLoginLinkToShop(
                $messagePayload['miraklShopId'],
                $accountMapping
            );

            $shops = $this->miraklClient->listShopsByIds([$messagePayload['miraklShopId']]);
            foreach ($shops as $shop) {
                try {
                    $details = $this->sellerOnboardingService->getStripeAccountDetailsFromShop($shop, 'update');
                    $this->logger->info('KYC update status - Started updating Stripe Account ID: ' . $accountMapping->getStripeAccountId() . ' for Mirakl Shop: ' . $shop->getId());
                    if ($stripeAccount && $stripeAccount->id) {
                        $this->logger->info('KYC update status - Details to update Stripe Account: ' . json_encode($details));
                        $this->logger->info('KYC update status - Stripe Account ID: ' . $stripeAccount->id);
                        $this->stripeClient->updateStripeAccount($stripeAccount->id, $details, []);
                    }
                    $this->logger->info('KYC update status - End of updating Stripe Account for Mirakl Shop: ' . $shop->getId());
                } catch (\Throwable $e) {
                    $this->logger->info('KYC update status - Error updating Stripe Account ID: ' . $accountMapping->getStripeAccountId() . ' for Mirakl Shop: ' . $shop->getId() . ', error: ' . $e->getMessage());
                }
            }
        }
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
