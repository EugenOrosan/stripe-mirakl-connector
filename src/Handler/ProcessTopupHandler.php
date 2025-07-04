<?php

namespace App\Handler;

use App\Entity\StripeTopup;
use App\Message\ProcessTopupMessage;
use App\Repository\StripeTopupRepository;
use App\Service\StripeClient;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

class ProcessTopupHandler implements MessageHandlerInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var StripeClient
     */
    private $stripeClient;

    /**
     * @var StripeTopupRepository
     */
    private $stripeTopupRepository;

    /**
     * @param StripeClient $stripeClient
     * @param StripeTopupRepository $stripeTopupRepository
     */
    public function __construct(
        StripeClient $stripeClient,
        StripeTopupRepository $stripeTopupRepository
    ) {
        $this->stripeClient = $stripeClient;
        $this->stripeTopupRepository = $stripeTopupRepository;
    }

    public function __invoke(ProcessTopupMessage $message): void
    {
        $topup = $this->stripeTopupRepository->findOneBy([
            'id' => $message->getStripeTopupId(),
        ]);
        assert(null !== $topup);
        assert(StripeTopup::TOPUP_CREATED !== $topup->getStatus());

        try {
            $response = $this->stripeClient->createTopup(
                (string)$topup->getCurrency(),
                (int)$topup->getAmount(),
                'Mirakl ' . date('m/d'),
                'Mirakl ' . date('m/d')
            );

            $topup->setTopupId($response->id);
            $topup->setStatus(StripeTopup::TOPUP_CREATED);
            $topup->setStatusReason(null);
        } catch (ApiErrorException $e) {
            $this->logger->error(
                sprintf('Could not create Stripe Topup: %s.', $e->getMessage()),
                [
                    'stripeErrorCode' => $e->getStripeCode(),
                    'file' => $e->getFile() ??  'No file available.',
                    'line' => $e->getLine() ?? 'No line available.',
                    'trace' => $e->getTraceAsString() ?? 'No trace available.',
                ]
            );

            $topup->setStatus(StripeTopup::TOPUP_FAILED);
            $topup->setStatusReason(substr($e->getMessage(), 0, 1024));
        }

        $this->stripeTopupRepository->flush();
    }
}
