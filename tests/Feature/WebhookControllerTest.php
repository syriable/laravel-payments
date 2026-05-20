<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Syriable\Payments\Contracts\Gateway as GatewayContract;
use Syriable\Payments\Data\Checkout;
use Syriable\Payments\Data\PaymentResult;
use Syriable\Payments\Data\WebhookEvent;
use Syriable\Payments\Enums\PaymentStatus;
use Syriable\Payments\Events\PaymentFailed;
use Syriable\Payments\Events\PaymentRefunded;
use Syriable\Payments\Events\PaymentSucceeded;
use Syriable\Payments\Facades\Gateway;

it('registers the webhook route', function (): void {
    expect(route('payment-gateways.webhook', ['gateway' => 'stripe']))
        ->toContain('/payment-gateways/webhook/stripe');
});

it('dispatches PaymentSucceeded for a valid stripe webhook', function (): void {
    Event::fake([PaymentSucceeded::class]);

    $secret = 'whsec_test_dummy';
    $body = json_encode([
        'type' => 'checkout.session.completed',
        'data' => ['object' => ['id' => 'cs_test_777']],
    ]);
    $timestamp = time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

    $response = $this->call(
        'POST',
        '/payment-gateways/webhook/stripe',
        server: ['HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}", 'CONTENT_TYPE' => 'application/json'],
        content: $body,
    );

    $response->assertOk();

    Event::assertDispatched(
        PaymentSucceeded::class,
        static fn (PaymentSucceeded $e): bool => $e->event->paymentId === 'cs_test_777'
            && $e->event->gateway === 'stripe',
    );
});

it('returns 403 for a webhook with an invalid signature', function (): void {
    Event::fake([PaymentSucceeded::class, PaymentFailed::class, PaymentRefunded::class]);

    $response = $this->call(
        'POST',
        '/payment-gateways/webhook/stripe',
        server: ['HTTP_STRIPE_SIGNATURE' => 't='.time().',v1=bad', 'CONTENT_TYPE' => 'application/json'],
        content: json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'x']]]),
    );

    $response->assertForbidden();

    Event::assertNotDispatched(PaymentSucceeded::class);
    Event::assertNotDispatched(PaymentFailed::class);
    Event::assertNotDispatched(PaymentRefunded::class);
});

it('returns 404 for an unknown gateway', function (): void {
    $response = $this->call(
        'POST',
        '/payment-gateways/webhook/does-not-exist',
        server: ['CONTENT_TYPE' => 'application/json'],
        content: '{}',
    );

    $response->assertNotFound();
});

it('dispatches PaymentRefunded for a refund webhook from a custom gateway', function (): void {
    Event::fake([PaymentRefunded::class]);

    Gateway::extend('demo', fn () => new class implements GatewayContract
    {
        public function name(): string
        {
            return 'demo';
        }

        public function checkout(Checkout $checkout): PaymentResult
        {
            return new PaymentResult('d1', PaymentStatus::Pending);
        }

        public function webhook(Request $request): WebhookEvent
        {
            return new WebhookEvent('demo', 'payment.refunded', 'd1');
        }
    });

    $response = $this->call(
        'POST',
        '/payment-gateways/webhook/demo',
        server: ['CONTENT_TYPE' => 'application/json'],
        content: '{}',
    );

    $response->assertOk();
    Event::assertDispatched(PaymentRefunded::class);
});
