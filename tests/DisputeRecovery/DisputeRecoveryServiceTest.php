<?php

namespace App\Tests\DisputeRecovery;

use App\Controller\StripeWebhookEndpoint;
use App\Entity\AccountMapping;
use App\Entity\StripeDispute;
use App\Entity\StripeDisputeAdjustment;
use App\Entity\StripeTransfer;
use App\Factory\StripeTransferFactory;
use App\Repository\AccountMappingRepository;
use App\Repository\PaymentMappingRepository;
use App\Repository\StripeTransferRepository;
use App\Handler\ProcessTransferHandler;
use App\Message\ProcessTransferMessage;
use App\Service\MiraklClient;
use App\Service\DisputeRecoveryService;
use App\Service\StripeClient;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\Setup;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Version\Version;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Psr\Log\LoggerInterface;
use Stripe\Event;
use Stripe\Exception\ApiConnectionException;
use Stripe\HttpClient\ClientInterface;
use Stripe\Transfer;
use Shivas\VersioningBundle\Service\VersionManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;

class DisputeRecoveryServiceTest extends TestCase
{
    private EntityManager $entityManager;
    private StripeClient $stripeClient;
    private DisputeRecoveryService $service;
    private ?string $schema = null;
    private int $invoiceId = 0;

    protected function setUp(): void
    {
        $configuration = Setup::createAnnotationMetadataConfiguration(
            [dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Entity'],
            true,
            null,
            null,
            false
        );
        $configuration->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
        $url = getenv('DISPUTE_TEST_DATABASE_URL');
        $connection = $url ? ['url' => $url] : ['driver' => 'pdo_sqlite', 'memory' => true];
        $this->entityManager = EntityManager::create($connection, $configuration);
        if ($url) {
            $this->schema = 'dispute_test_' . bin2hex(random_bytes(6));
            $this->entityManager->getConnection()->executeStatement('CREATE SCHEMA ' . $this->schema);
            $this->entityManager->getConnection()->executeStatement('SET search_path TO ' . $this->schema);
        }
        $metadata = array_map([$this->entityManager, 'getClassMetadata'], [
            AccountMapping::class,
            StripeTransfer::class,
            StripeDispute::class,
            StripeDisputeAdjustment::class,
        ]);
        (new SchemaTool($this->entityManager))->createSchema($metadata);

        $this->stripeClient = $this->createMock(StripeClient::class);
        $this->stripeClient->expects(self::never())->method('createPayout');
        $this->useService(true, false);
    }

    private function useService(bool $enabled, bool $includePreviousDisputes): void
    {
        $this->service = new DisputeRecoveryService($this->entityManager, $enabled, $includePreviousDisputes);
        $this->service->setLogger(new NullLogger());
    }

    private function process(StripeTransfer $transfer): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->entityManager);
        $handler = new ProcessTransferHandler(
            $this->stripeClient,
            new StripeTransferRepository($registry),
            $this->createMock(MiraklClient::class),
            'acct_urbn',
            $this->service
        );
        $handler->setLogger(new NullLogger());
        $handler(new ProcessTransferMessage($transfer->getId()));
    }

    protected function tearDown(): void
    {
        if (null !== $this->schema) {
            $connection = $this->entityManager->getConnection();
            $connection->executeStatement('SET search_path TO public');
            $connection->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
        }
        $this->entityManager->close();
    }

    public function testCostUsesNetIncludingFeesAndReinstatements(): void
    {
        $this->service->recordEvent($this->event('dp_cost', [-11500, 10000], 'won'));
        $dispute = $this->dispute('dp_cost');
        self::assertSame(1500, $dispute->getCost());
        self::assertSame(0, $dispute->getAlreadyRecovered());
        self::assertFalse($dispute->isFullyRecovered());
    }

    public function testSignedWebhookPersistsTheDisputeWithoutAnyStripeApiRequest(): void
    {
        $version = $this->createMock(VersionManagerInterface::class);
        $version->method('getVersion')->willReturn(\Version\Version::fromString('1.0.0'));
        $client = new StripeClient($version, 'sk_test_local_validation', true);
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::never())->method('request');
        $client->setHttpClient($http);
        $controller = new StripeWebhookEndpoint(
            $this->createMock(MessageBusInterface::class),
            $client,
            $this->createMock(AccountMappingRepository::class),
            $this->createMock(PaymentMappingRepository::class),
            'seller_secret',
            'operator_secret',
            'mirakl_order_id',
            $this->service
        );
        $controller->setLogger(new NullLogger());
        $payload = $this->event('dp_signed', [-11500])->toJSON();
        $timestamp = time();
        $signature = 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, 'operator_secret');
        try {
            $response = $controller->handleStripeOperatorWebhook(Request::create(
                '/',
                'POST',
                [],
                [],
                [],
                ['HTTP_STRIPE_SIGNATURE' => $signature],
                $payload
            ));
            self::assertSame(200, $response->getStatusCode());
            self::assertSame(11500, $this->dispute('dp_signed')->getCost());
            $invalid = $controller->handleStripeOperatorWebhook(Request::create(
                '/',
                'POST',
                [],
                [],
                [],
                ['HTTP_STRIPE_SIGNATURE' => 't=' . $timestamp . ',v1=invalid'],
                $payload
            ));
            self::assertSame(400, $invalid->getStatusCode());
        } finally {
            \Stripe\ApiRequestor::setHttpClient(new \Stripe\HttpClient\CurlClient());
        }
    }

    public function testDuplicateAndOutOfOrderSnapshotsDoNotRegressTheCost(): void
    {
        $closed = $this->event('dp_order', [-11500], 'lost');
        $this->service->recordEvent($closed);
        $this->service->recordEvent($closed);
        $this->service->recordEvent($this->event('dp_order', [-11500, 11500], 'won', 'usd', 'charge.dispute.funds_reinstated'));
        $this->service->recordEvent($closed);
        self::assertSame(0, $this->dispute('dp_order')->getCost());
        self::assertSame(1, $this->entityManager->getRepository(StripeDispute::class)->count([]));
    }

    public function testEmptyBalanceTransactionsDoNotInventACost(): void
    {
        $this->service->recordEvent($this->event('dp_warning', [], 'warning_closed'));
        self::assertSame(0, $this->dispute('dp_warning')->getCost());
        self::assertTrue($this->dispute('dp_warning')->isFullyRecovered());
    }

    public function testPartialRecoveryCarriesForwardAcrossDifferentShops(): void
    {
        $this->service->recordEvent($this->event('dp_partial', [-10000]));
        $first = $this->transfer(4000, 'usd', 1);
        $this->process($first);
        self::assertSame(0, $first->getAmount());
        self::assertNull($first->getTransferId());
        self::assertSame(StripeTransfer::TRANSFER_COMMISSION_TAX_COVERED_BY_DISPUTES, $first->getStatus());
        self::assertSame(4000, $this->dispute('dp_partial')->getAlreadyRecovered());

        $this->stripeClient->expects(self::once())->method('createTransfer')
            ->with('usd', 3000, 'acct_urbn', null, self::callback(static function (array $metadata): bool {
                return 'dp_partial' === $metadata['disputes_ids'] && 2 === $metadata['miraklShopId'];
            }))
            ->willReturn(Transfer::constructFrom(['id' => 'tr_partial']));
        $second = $this->transfer(9000, 'usd', 2);
        $this->process($second);
        $this->process($first);
        self::assertSame(10000, $this->dispute('dp_partial')->getAlreadyRecovered());
        self::assertTrue($this->dispute('dp_partial')->isFullyRecovered());
        self::assertSame(2, $this->entityManager->getRepository(StripeDisputeAdjustment::class)->count([]));
    }

    public function testDisabledFlagCollectsDisputesButDoesNotDeductThem(): void
    {
        $this->useService(false, false);
        $this->service->recordEvent($this->event('dp_disabled', [-1000]));
        $this->stripeClient->expects(self::exactly(3))->method('createTransfer')
            ->withConsecutive(
                ['usd', 5000, 'acct_urbn', null, self::anything()],
                ['usd', 5000, 'acct_urbn', null, self::anything()],
                ['usd', 4000, 'acct_urbn', null, self::anything()]
            )
            ->willReturn(Transfer::constructFrom(['id' => 'tr_flag']));
        $this->process($this->transfer(5000));
        self::assertSame(0, $this->dispute('dp_disabled')->getAlreadyRecovered());
        $this->useService(true, false);
        $this->process($this->transfer(5000));
        self::assertSame(0, $this->dispute('dp_disabled')->getAlreadyRecovered());
        $this->useService(true, true);
        $this->process($this->transfer(5000));
        self::assertTrue($this->dispute('dp_disabled')->isFullyRecovered());
    }

    public function testSimulationLogsReadableTotalsAndDoesNotConsumeDisputes(): void
    {
        $this->useService(false, false);
        $this->service->recordEvent($this->event('dp_large', [-68639]));
        $this->service->recordEvent($this->event('dp_small', [-5900]));
        $this->service->recordEvent($this->event('dp_won', [-7096, 5596], 'won'));
        $logs = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(static function ($message, array $context) use (&$logs): void {
            $logs[] = ['message' => $message, 'context' => $context];
        });
        $this->service->setLogger($logger);
        $first = $this->transfer(14700);
        $this->service->prepareCommissionTaxTransfer($first, ['miraklShopId' => 1]);
        $second = $this->transfer(5760);
        $this->service->prepareCommissionTaxTransfer($second, ['miraklShopId' => 1]);

        $context = $logs[0]['context'];
        self::assertStringContainsString('SIMULATION ONLY', $logs[0]['message']);
        self::assertSame($first->getMiraklId(), $context['invoiceId']);
        self::assertSame(1, $context['shopId']);
        self::assertSame('147.00 USD', $context['commissionAndTaxBeforeDeduction']);
        self::assertSame('760.39 USD', $context['eligibleDisputesInitialCost']);
        self::assertSame('0.00 USD', $context['eligibleDisputesAlreadyRecoveredInDatabase']);
        self::assertSame('760.39 USD', $context['eligibleDisputesOutstandingBeforeThisTransfer']);
        self::assertSame('147.00 USD', $context['proposedDeduction']);
        self::assertSame('0.00 USD', $context['commissionAndTaxAfterProposedDeduction']);
        self::assertSame('613.39 USD', $context['eligibleDisputesOutstandingAfterProposedDeduction']);
        self::assertSame('147.00 USD', $context['amountToSendToStripe']);
        self::assertTrue($context['stripeTransferRequired']);
        self::assertCount(3, $context['disputes']);
        self::assertSame('539.39 USD', $context['disputes']['dp_large']['outstandingAfterProposedDeduction']);
        self::assertFalse($context['disputes']['dp_large']['eligibleIfRecoveryEnabledWithCurrentIncludePreviousFlag']);
        self::assertStringContainsString('not a cumulative simulation', $context['simulationScope']);
        self::assertSame('760.39 USD', $logs[1]['context']['eligibleDisputesOutstandingBeforeThisTransfer']);
        self::assertSame('702.79 USD', $logs[1]['context']['eligibleDisputesOutstandingAfterProposedDeduction']);
        self::assertSame(0, $this->dispute('dp_large')->getAlreadyRecovered());
        self::assertSame(0, $this->entityManager->getRepository(StripeDisputeAdjustment::class)->count([]));

        $this->service->prepareCommissionTaxTransfer($first, []);
        self::assertStringContainsString('retry uses the saved', $logs[2]['message']);
        self::assertSame($first->getCommissionTaxRequest(), $logs[2]['context']['savedRequest']);
    }

    public function testSimulationLogsWhenNoDisputesAreEligible(): void
    {
        $this->useService(false, false);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            self::stringContains('SIMULATION ONLY'),
            self::callback(static function (array $context): bool {
                return 'No eligible disputes found.' === $context['calculation']
                    && '0.00 USD' === $context['proposedDeduction']
                    && '50.00 USD' === $context['amountToSendToStripe']
                    && [] === $context['disputes'];
            })
        );
        $this->service->setLogger($logger);
        $this->service->prepareCommissionTaxTransfer($this->transfer(5000), []);
    }

    public function testSimulationLogsZeroDecimalCurrencyWithoutDividingBy100(): void
    {
        $this->useService(false, false);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            self::stringContains('SIMULATION ONLY'),
            self::callback(static function (array $context): bool {
                return '5000 JPY' === $context['commissionAndTaxBeforeDeduction'];
            })
        );
        $this->service->setLogger($logger);
        $this->service->prepareCommissionTaxTransfer($this->transfer(5000, 'jpy'), []);
    }

    public function testOnlyDisputesClosedAfterEnablingAreDeductedByDefault(): void
    {
        $this->useService(false, false);
        $this->service->recordEvent($this->event('dp_before', [-1000]));
        $this->useService(true, false);
        $this->service->recordEvent($this->event('dp_after', [-2000]));
        $this->service->recordEvent($this->event('dp_before', [-1000, 1000], 'won', 'usd', 'charge.dispute.funds_reinstated'));
        self::assertFalse($this->dispute('dp_before')->isClosedWhileRecoveryEnabled());
        self::assertTrue($this->dispute('dp_after')->isClosedWhileRecoveryEnabled());
        $this->stripeClient->expects(self::once())->method('createTransfer')
            ->with('usd', 3000, 'acct_urbn', null, self::callback(static function (array $metadata): bool {
                return 'dp_after' === $metadata['disputes_ids'];
            }))
            ->willReturn(Transfer::constructFrom(['id' => 'tr_after']));
        $this->process($this->transfer(5000));
        self::assertSame(0, $this->dispute('dp_before')->getAlreadyRecovered());
    }

    public function testDisabledFlagSendsTheOriginalAmountWithOriginalMetadata(): void
    {
        $this->useService(false, true);
        $this->service->recordEvent($this->event('dp_off', [-1000]));
        $this->stripeClient->expects(self::once())->method('createTransfer')
            ->with('usd', 5000, 'acct_urbn', null, ['miraklId' => '1', 'miraklShopId' => 1])
            ->willReturn(Transfer::constructFrom(['id' => 'tr_off']));
        $transfer = $this->transfer(5000);
        $this->process($transfer);
        self::assertSame(StripeTransfer::TRANSFER_CREATED, $transfer->getStatus());
        self::assertSame('tr_off', $transfer->getTransferId());
        self::assertSame(5000, $transfer->getAmount());
        self::assertSame(4000, $transfer->getCommissionTaxRequest()['simulated_amount']);
        self::assertSame(['dp_off' => 1000], $transfer->getCommissionTaxRequest()['simulated_disputes']);
        self::assertSame(0, $this->entityManager->getRepository(StripeDisputeAdjustment::class)->count([]));
        self::assertSame(0, $this->dispute('dp_off')->getAlreadyRecovered());
    }

    public function testDisabledFlagSimulatesDisputesClosedWhileDisabled(): void
    {
        $this->useService(false, false);
        $this->service->recordEvent($this->event('dp_sim', [-1000]));
        $this->stripeClient->expects(self::exactly(2))->method('createTransfer')
            ->with('usd', 5000, 'acct_urbn', null, self::callback(static function (array $metadata): bool {
                return !isset($metadata['disputes_ids']) && !isset($metadata['commission_tax_original_amount']);
            }))
            ->willReturn(Transfer::constructFrom(['id' => 'tr_sim']));
        $first = $this->transfer(5000);
        $this->process($first);
        $second = $this->transfer(5000);
        $this->process($second);
        self::assertSame(4000, $first->getCommissionTaxRequest()['simulated_amount']);
        self::assertSame(4000, $second->getCommissionTaxRequest()['simulated_amount']);
        self::assertFalse($this->dispute('dp_sim')->isFullyRecovered());
    }

    public function testReinstatementAfterRecoveryCreditsTheNextTransfer(): void
    {
        $this->service->recordEvent($this->event('dp_credit', [-10000]));
        $this->stripeClient->expects(self::exactly(3))->method('createTransfer')
            ->withConsecutive(
                ['usd', 10000, 'acct_urbn', null, self::anything()],
                ['usd', 15000, 'acct_urbn', null, self::callback(static function (array $metadata): bool {
                    return '-10000' === $metadata['disputes_adjustment'];
                })],
                ['usd', 5000, 'acct_urbn', null, self::anything()]
            )
            ->willReturn(Transfer::constructFrom(['id' => 'tr_credit']));
        $this->process($this->transfer(20000));
        $this->service->recordEvent($this->event('dp_credit', [-10000, 10000], 'won', 'usd', 'charge.dispute.funds_reinstated'));
        self::assertFalse($this->dispute('dp_credit')->isFullyRecovered());
        self::assertSame(-10000, $this->dispute('dp_credit')->getOutstanding());
        $this->process($this->transfer(5000));
        self::assertSame(0, $this->dispute('dp_credit')->getAlreadyRecovered());
        self::assertTrue($this->dispute('dp_credit')->isFullyRecovered());
        $this->process($this->transfer(5000));
    }

    public function testCostReductionAfterPartialRecoveryCanProduceACredit(): void
    {
        $this->service->recordEvent($this->event('dp_partial_credit', [-10000]));
        $this->process($this->transfer(4000));
        $this->service->recordEvent($this->event('dp_partial_credit', [-10000, 8000], 'won', 'usd', 'charge.dispute.funds_reinstated'));
        $this->stripeClient->expects(self::once())->method('createTransfer')
            ->with('usd', 7000, 'acct_urbn', null, self::anything())
            ->willReturn(Transfer::constructFrom(['id' => 'tr_partial_credit']));
        $this->process($this->transfer(5000));
        self::assertSame(2000, $this->dispute('dp_partial_credit')->getAlreadyRecovered());
    }

    public function testAnOpenDisputeIsNotRecoveredBeforeClosure(): void
    {
        $this->service->recordEvent($this->event('dp_open', [-10000, 9000], 'under_review', 'usd', 'charge.dispute.funds_reinstated'));
        $this->stripeClient->expects(self::exactly(2))->method('createTransfer')
            ->withConsecutive(
                ['usd', 5000, 'acct_urbn', null, self::anything()],
                ['usd', 4000, 'acct_urbn', null, self::anything()]
            )
            ->willReturn(Transfer::constructFrom(['id' => 'tr_open']));
        $this->process($this->transfer(5000));
        $this->service->recordEvent($this->event('dp_open', [-10000, 9000], 'lost'));
        $this->process($this->transfer(5000));
    }

    public function testCurrenciesAreNotMixed(): void
    {
        $this->service->recordEvent($this->event('dp_usd', [-1000]));
        $this->stripeClient->expects(self::once())->method('createTransfer')
            ->with('eur', 5000, 'acct_urbn', null, self::anything())
            ->willReturn(Transfer::constructFrom(['id' => 'tr_eur']));
        $this->process($this->transfer(5000, 'eur'));
        self::assertSame(1000, $this->dispute('dp_usd')->getOutstanding());
    }

    public function testSettlementCurrencyComesFromTheBalanceTransactions(): void
    {
        $event = $this->event('dp_fx', [-1000]);
        $event->data->object->currency = 'eur';
        $this->service->recordEvent($event);
        self::assertSame('usd', $this->dispute('dp_fx')->getCurrency());
    }

    public function testNegativeOriginalTcKeepsItsExistingDirection(): void
    {
        $this->service->recordEvent($this->event('dp_negative', [-1000]));
        $transfer = $this->transfer(3000);
        $transfer->setReversed(true);
        $this->entityManager->flush();
        $this->stripeClient->expects(self::never())->method('createTransfer');
        $this->stripeClient->expects(self::once())->method('createTransferFromConnectedAccount')
            ->with('usd', 3000, 'acct_urbn', self::anything())
            ->willReturn(Transfer::constructFrom(['id' => 'tr_reversed']));
        $this->process($transfer);
        self::assertSame(1000, $this->dispute('dp_negative')->getOutstanding());
    }

    public function testFailedRequestIsRetriedWithExactlyTheSameRequest(): void
    {
        $this->service->recordEvent($this->event('dp_retry', [-10000]));
        $requests = [];
        $this->stripeClient->expects(self::exactly(2))->method('createTransfer')
            ->willReturnCallback(static function (...$arguments) use (&$requests): Transfer {
                $requests[] = $arguments;
                if (1 === count($requests)) {
                    throw ApiConnectionException::factory('Simulated timeout after sending the request.');
                }

                return Transfer::constructFrom(['id' => 'tr_retry']);
            });
        $transfer = $this->transfer(20000);
        $this->process($transfer);
        self::assertSame(StripeTransfer::TRANSFER_COMMISSION_TAX_FAILED, $transfer->getStatus());
        self::assertSame(0, $this->dispute('dp_retry')->getAlreadyRecovered());
        $factory = $this->getMockBuilder(StripeTransferFactory::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $factory->updateFromCommissionTaxInvoice($transfer, [], StripeTransfer::TRANSFER_COMMISSION_TAX);
        self::assertSame(10000, $transfer->getAmount());
        self::assertSame(StripeTransfer::TRANSFER_PENDING, $transfer->getStatus());
        $this->process($transfer);
        self::assertSame($requests[0], $requests[1]);
        self::assertSame(10000, $this->dispute('dp_retry')->getAlreadyRecovered());
        self::assertSame(StripeTransfer::TRANSFER_CREATED, $transfer->getStatus());
    }

    public function testPendingAdjustmentBlocksAnotherTransferInTheSameCurrency(): void
    {
        $this->service->recordEvent($this->event('dp_pending', [-1000]));
        $attempt = 0;
        $this->stripeClient->expects(self::exactly(3))->method('createTransfer')
            ->willReturnCallback(static function () use (&$attempt): Transfer {
                if (1 === ++$attempt) {
                    throw ApiConnectionException::factory('Temporary failure.');
                }

                return Transfer::constructFrom(['id' => 'tr_pending_' . $attempt]);
            });
        $first = $this->transfer(5000);
        $second = $this->transfer(5000, 'usd', 2);
        $this->process($first);
        $this->service->recordEvent($this->event('dp_pending', [-1000, 1000], 'won', 'usd', 'charge.dispute.funds_reinstated'));
        $this->process($second);
        self::assertSame(StripeTransfer::TRANSFER_COMMISSION_TAX_ON_HOLD, $second->getStatus());
        self::assertNull($second->getCommissionTaxRequest());
        $this->process($first);
        $this->process($second);
        self::assertSame(6000, $second->getAmount());
        self::assertTrue($this->dispute('dp_pending')->isFullyRecovered());
    }

    public function testRetryUsesTheRequestReloadedFromTheDatabase(): void
    {
        $this->service->recordEvent($this->event('dp_reload', [-1000]));
        $calls = [];
        $this->stripeClient->expects(self::exactly(2))->method('createTransfer')
            ->willReturnCallback(static function (...$arguments) use (&$calls): Transfer {
                $calls[] = $arguments;
                if (1 === count($calls)) {
                    throw ApiConnectionException::factory('Worker stopped before confirmation.');
                }

                return Transfer::constructFrom(['id' => 'tr_reload']);
            });
        $transfer = $this->transfer(5000);
        $id = $transfer->getId();
        $this->process($transfer);
        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(StripeTransfer::class, $id);
        $this->process($reloaded);
        self::assertSame($calls[0], $calls[1]);
        self::assertTrue($this->dispute('dp_reload')->isFullyRecovered());
    }

    public function testCreditsAndLossesAreAllocatedWithoutOverRecovery(): void
    {
        $this->service->recordEvent($this->event('dp_old_loss', [-1000]));
        $this->process($this->transfer(1000));
        $this->service->recordEvent($this->event('dp_old_loss', [-1000, 1000], 'won', 'usd', 'charge.dispute.funds_reinstated'));
        $this->service->recordEvent($this->event('dp_new_loss', [-4000]));
        $this->process($this->transfer(2000));
        self::assertSame(0, $this->dispute('dp_old_loss')->getOutstanding());
        self::assertSame(1000, $this->dispute('dp_new_loss')->getOutstanding());
        self::assertSame(3000, $this->dispute('dp_new_loss')->getAlreadyRecovered());
        self::assertSame(3, $this->entityManager->getRepository(StripeDisputeAdjustment::class)->count([]));
    }

    public function testAnIgnoredTransferIsNotSent(): void
    {
        $transfer = $this->transfer(5000);
        $transfer->setStatus(StripeTransfer::TRANSFER_COMMISSION_TAX_IGNORED);
        $this->entityManager->flush();
        $this->stripeClient->expects(self::never())->method('createTransfer');
        $this->process($transfer);
        self::assertNull($transfer->getCommissionTaxRequest());
    }

    public function testPreparedAdjustmentsAreNotRewrittenWhenFlagChanges(): void
    {
        $this->service->recordEvent($this->event('dp_toggle', [-1000]));
        $attempt = 0;
        $this->stripeClient->expects(self::exactly(2))->method('createTransfer')
            ->with('usd', 4000, 'acct_urbn', null, self::anything())
            ->willReturnCallback(static function () use (&$attempt): Transfer {
                if (1 === ++$attempt) {
                    throw ApiConnectionException::factory('Temporary failure.');
                }

                return Transfer::constructFrom(['id' => 'tr_toggle']);
            });
        $transfer = $this->transfer(5000);
        $this->process($transfer);
        $this->useService(false, false);
        $this->process($transfer);
        self::assertTrue($this->dispute('dp_toggle')->isFullyRecovered());
    }

    public function testMetadataFallsBackToBatchReferenceAbove500Characters(): void
    {
        for ($i = 0; $i < 20; ++$i) {
            $this->service->recordEvent($this->event('dp_' . str_repeat('a', 25) . $i, [-1]));
        }
        $this->stripeClient->expects(self::once())->method('createTransfer')
            ->with('usd', 80, 'acct_urbn', null, self::callback(static function (array $metadata): bool {
                return isset($metadata['disputes_batch']) && !isset($metadata['disputes_ids']);
            }))
            ->willReturn(Transfer::constructFrom(['id' => 'tr_batch']));
        $this->process($this->transfer(100));
        self::assertSame(20, $this->entityManager->getRepository(StripeDisputeAdjustment::class)->count([]));
    }

    public function testMetadataAllowsExactly500Characters(): void
    {
        $firstId = 'dp_' . str_repeat('a', 246);
        $secondId = 'dp_' . str_repeat('b', 247);
        $this->service->recordEvent($this->event($firstId, [-1]));
        $this->service->recordEvent($this->event($secondId, [-1]));
        $this->stripeClient->expects(self::once())->method('createTransfer')
            ->with('usd', 98, 'acct_urbn', null, self::callback(static function (array $metadata): bool {
                return 500 === strlen($metadata['disputes_ids']);
            }))
            ->willReturn(Transfer::constructFrom(['id' => 'tr_metadata_limit']));
        $this->process($this->transfer(100));
    }

    public function testConflictingBalanceTransactionIsRejected(): void
    {
        $this->service->recordEvent($this->event('dp_conflict', [-1000]));
        $this->expectException(\UnexpectedValueException::class);
        $this->service->recordEvent($this->event('dp_conflict', [-2000]));
    }

    public function testMissingBalanceTransactionsIsRejected(): void
    {
        $event = $this->event('dp_missing', []);
        unset($event->data->object->balance_transactions);
        $this->expectException(\UnexpectedValueException::class);
        $this->service->recordEvent($event);
    }

    public function testMixedBalanceTransactionCurrenciesAreRejected(): void
    {
        $event = $this->event('dp_mixed', [-1000, 1000]);
        $transactions = $event->data->object->balance_transactions;
        $transactions[1]->currency = 'eur';
        $this->expectException(\UnexpectedValueException::class);
        $this->service->recordEvent($event);
    }

    public function testNewTransferDoesNotNeedAnInitializedStatusToCheckCompletion(): void
    {
        self::assertFalse((new StripeTransfer())->isCommissionTaxCompleted());
    }

    public function testBacklogKeepsTheOriginalRetriableStatuses(): void
    {
        $this->service->recordEvent($this->event('dp_backlog', [-1000]));
        $this->stripeClient->method('createTransfer')
            ->willThrowException(ApiConnectionException::factory('Simulated failure.'));
        $transfer = $this->transfer(5000);
        $this->process($transfer);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->entityManager);
        $repository = new StripeTransferRepository($registry);
        $backlog = $repository->findRetriableCommissionTaxInvoiceTransfers();
        self::assertSame($transfer, $backlog[$transfer->getMiraklId()][StripeTransfer::TRANSFER_COMMISSION_TAX]);
        $transfer->setStatus(StripeTransfer::TRANSFER_PENDING);
        $this->entityManager->flush();
        self::assertSame([], $repository->findRetriableCommissionTaxInvoiceTransfers());
    }

    public function testProductionMigrationAndItsRollbackOnPostgres(): void
    {
        if (null === $this->schema) {
            self::markTestSkipped('PostgreSQL is required to exercise the production migration.');
        }
        $connection = $this->entityManager->getConnection();
        $this->entityManager->clear();
        $connection->executeStatement('DROP TABLE stripe_dispute_adjustment');
        $connection->executeStatement('DROP SEQUENCE stripe_dispute_adjustment_id_seq');
        $connection->executeStatement('DROP TABLE stripe_dispute');
        $connection->executeStatement('ALTER TABLE stripe_transfer DROP commission_tax_request');
        require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Migrations' . DIRECTORY_SEPARATOR . 'Version20261104000001.php';
        $version = $this->createMock(Version::class);
        $version->method('getConfiguration')->willReturn(new Configuration($connection));
        $version->method('addSql')->willReturnCallback(static function (string $sql, array $params = [], array $types = []) use ($connection): void {
            $connection->executeStatement($sql, $params, $types);
        });
        $migration = new \DoctrineMigrations\Version20261104000001($version);
        $migration->up(new Schema());
        $this->service->recordEvent($this->event('dp_migration', [-1000]));
        $this->process($this->transfer(500));
        $this->entityManager->clear();
        self::assertSame(500, $this->dispute('dp_migration')->getAlreadyRecovered());
        $migration->down(new Schema());
        self::assertFalse($connection->getSchemaManager()->tablesExist(['stripe_dispute']));
        self::assertFalse($connection->getSchemaManager()->tablesExist(['stripe_dispute_adjustment']));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM stripe_transfer'));
    }

    private function event(
        string $id,
        array $nets,
        string $status = 'lost',
        string $currency = 'usd',
        string $type = 'charge.dispute.closed'
    ): Event {
        $transactions = [];
        foreach ($nets as $index => $net) {
            $transactions[] = [
                'id' => 'txn_' . $id . '_' . $index,
                'object' => 'balance_transaction',
                'net' => $net,
                'currency' => $currency,
            ];
        }

        return Event::constructFrom([
            'id' => 'evt_' . bin2hex(random_bytes(8)),
            'object' => 'event',
            'type' => $type,
            'created' => 1793790000,
            'data' => ['object' => [
                'id' => $id,
                'object' => 'dispute',
                'charge' => 'ch_' . $id,
                'amount' => 999999,
                'currency' => $currency,
                'status' => $status,
                'balance_transactions' => $transactions,
            ]],
        ]);
    }

    private function transfer(int $amount, string $currency = 'usd', int $shopId = 1): StripeTransfer
    {
        $mapping = $this->entityManager->createQueryBuilder()
            ->select('a')->from(AccountMapping::class, 'a')
            ->where('a.miraklShopId = :shop')->setParameter('shop', $shopId)
            ->getQuery()->getOneOrNullResult();
        if (null === $mapping) {
            $mapping = new AccountMapping();
            $mapping->setMiraklShopId($shopId)->setStripeAccountId('acct_shop_' . $shopId);
            $mapping->setCreationDatetime(new \DateTimeImmutable())->setModificationDatetime(new \DateTimeImmutable());
            $this->entityManager->persist($mapping);
        }
        $transfer = new StripeTransfer();
        $transfer->setType(StripeTransfer::TRANSFER_COMMISSION_TAX);
        $transfer->setMiraklId((string) ++$this->invoiceId);
        $transfer->setAccountMapping($mapping);
        $transfer->setAmount($amount)->setCurrency($currency)->setStatus(StripeTransfer::TRANSFER_PENDING);
        $transfer->setCreationDatetime(new \DateTimeImmutable())->setModificationDatetime(new \DateTimeImmutable());
        $this->entityManager->persist($transfer);
        $this->entityManager->flush();

        return $transfer;
    }

    private function dispute(string $id): StripeDispute
    {
        $dispute = $this->entityManager->find(StripeDispute::class, $id);
        self::assertInstanceOf(StripeDispute::class, $dispute);

        return $dispute;
    }
}
