<?php

namespace App\Handler;

use App\Entity\StripeTopup;
use App\Entity\StripeTopupInvoiceData;
use App\Message\ProcessTopupMessage;
use App\Repository\StripeTopupInvoiceDataRepository;
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
     * @var StripeTopupInvoiceDataRepository
     */
    private $stripeTopupInvoiceDataRepository;

    /**
     * @param StripeClient $stripeClient
     * @param StripeTopupRepository $stripeTopupRepository
     * @param StripeTopupInvoiceDataRepository $stripeTopupInvoiceDataRepository
     */
    public function __construct(
        StripeClient $stripeClient,
        StripeTopupRepository $stripeTopupRepository,
        StripeTopupInvoiceDataRepository $stripeTopupInvoiceDataRepository
    ) {
        $this->stripeClient = $stripeClient;
        $this->stripeTopupRepository = $stripeTopupRepository;
        $this->stripeTopupInvoiceDataRepository = $stripeTopupInvoiceDataRepository;
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

            if ($topup->getInvoiceIds()) {
                foreach ($topup->getInvoiceIds() as $invoiceItem) {
                    $invoiceData = $this->stripeTopupInvoiceDataRepository->findOneBy(['invoiceNumber' => $invoiceItem['invoice_id']]);
                    if (!$invoiceData) {
                        $invoiceData = new StripeTopupInvoiceData();
                        $invoiceData->setInvoiceNumber($invoiceItem['invoice_id']);
                        $invoiceData->setAmount($invoiceItem['amount']);
                        $invoiceData->setTopupInternalId($topup->getId());
                        $invoiceData->setTopupStripeId($response->id);
                        $invoiceData->setCreatedAt(new \DateTimeImmutable());
                        $invoiceData->setDateCreatedFromMirakl(new \DateTimeImmutable($invoiceItem['date_created']));
                    }
                    $invoiceData->setUpdatedAt(new \DateTimeImmutable());
                    $invoiceData->setStatus(StripeTopupInvoiceData::INVOICE_TOPUP_COMPLETED);
                    $invoiceData->setTopupInternalId($topup->getId());
                    $invoiceData->setTopupStripeId($response->id);
                    $invoiceData->setStatusReason(null);

                    $this->stripeTopupInvoiceDataRepository->persist($invoiceData);
                }

                $this->stripeTopupInvoiceDataRepository->flush();
            }
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
