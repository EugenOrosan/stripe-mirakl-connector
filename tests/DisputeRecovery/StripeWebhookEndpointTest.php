<?php

namespace App\Tests\DisputeRecovery;

use App\Controller\StripeWebhookEndpoint;
use App\Repository\AccountMappingRepository;
use App\Repository\PaymentMappingRepository;
use App\Service\DisputeRecoveryService;
use App\Service\StripeClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stripe\Event;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;

class StripeWebhookEndpointTest extends TestCase
{
    /**
     * @dataProvider disputeEvents
     */
    public function testOperatorWebhookProcessesBothEvents(string $type): void
    {
        $event = $this->event($type);
        $service = $this->createMock(DisputeRecoveryService::class);
        $service->expects(self::once())->method('recordEvent')->with($event);
        $controller = $this->controller($event, $service, 'operator_secret');
        self::assertSame(200, $controller->handleStripeOperatorWebhook($this->request())->getStatusCode());
    }

    public function testSellerWebhookCannotRecordPlatformDisputes(): void
    {
        $service = $this->createMock(DisputeRecoveryService::class);
        $service->expects(self::never())->method('recordEvent');
        $controller = $this->controller($this->event('charge.dispute.closed'), $service, 'seller_secret');
        self::assertSame(200, $controller->handleStripeSellerWebhook($this->request())->getStatusCode());
    }

    public function testConnectedAccountEventIsIgnored(): void
    {
        $event = $this->event('charge.dispute.closed');
        $event->account = 'acct_connected';
        $service = $this->createMock(DisputeRecoveryService::class);
        $service->expects(self::never())->method('recordEvent');
        $controller = $this->controller($event, $service, 'operator_secret');
        self::assertSame(200, $controller->handleStripeOperatorWebhook($this->request())->getStatusCode());
    }

    public function testInvalidDisputePayloadIsReported(): void
    {
        $service = $this->createMock(DisputeRecoveryService::class);
        $service->method('recordEvent')->willThrowException(new \UnexpectedValueException('Missing balance_transactions.'));
        $controller = $this->controller($this->event('charge.dispute.closed'), $service, 'operator_secret');
        self::assertSame(400, $controller->handleStripeOperatorWebhook($this->request())->getStatusCode());
    }

    public function testDatabaseFailureReturnsAnErrorSoStripeCanRetry(): void
    {
        $service = $this->createMock(DisputeRecoveryService::class);
        $service->method('recordEvent')->willThrowException(new \RuntimeException('Database unavailable.'));
        $controller = $this->controller($this->event('charge.dispute.closed'), $service, 'operator_secret');
        self::assertSame(500, $controller->handleStripeOperatorWebhook($this->request())->getStatusCode());
    }

    public function disputeEvents(): array
    {
        return [
            ['charge.dispute.closed'],
            ['charge.dispute.funds_reinstated'],
        ];
    }

    private function controller(Event $event, DisputeRecoveryService $service, string $secret): StripeWebhookEndpoint
    {
        $stripeClient = $this->createMock(StripeClient::class);
        $stripeClient->expects(self::once())->method('webhookConstructEvent')
            ->with('{}', 'test_signature', $secret)->willReturn($event);
        $controller = new StripeWebhookEndpoint(
            $this->createMock(MessageBusInterface::class),
            $stripeClient,
            $this->createMock(AccountMappingRepository::class),
            $this->createMock(PaymentMappingRepository::class),
            'seller_secret',
            'operator_secret',
            'mirakl_order_id',
            $service
        );
        $controller->setLogger(new NullLogger());

        return $controller;
    }

    private function request(): Request
    {
        return Request::create('/', 'POST', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 'test_signature'], '{}');
    }

    private function event(string $type): Event
    {
        return Event::constructFrom([
            'id' => 'evt_test',
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => ['id' => 'dp_test', 'object' => 'dispute']],
        ]);
    }
}
