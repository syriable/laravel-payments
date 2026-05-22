<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Syriable\Payments\Data\Checkout;
use Syriable\Payments\Enums\PaymentStatus;
use Syriable\Payments\Exceptions\InvalidWebhookSignature;
use Syriable\Payments\Exceptions\PaymentFailed;
use Syriable\Payments\Gateways\Stripe\StripeGateway;

function stripeCheckout(): Checkout
{
    return new Checkout(
        amount: 2500,
        currency: 'USD',
        reference: 'order_99',
        successUrl: 'https://shop.test/success',
        cancelUrl: 'https://shop.test/cancel',
        customerEmail: 'buyer@example.test',
    );
}

function stripeGateway(HttpFactory $http): StripeGateway
{
    return new StripeGateway(
        config: ['secret' => 'sk_test_x', 'webhook_secret' => 'whsec_test_x'],
        http: $http,
    );
}

it('creates a checkout session and returns the redirect url', function (): void {
    $http = new HttpFactory;
    $http->fake([
        'api.stripe.com/v1/checkout/sessions' => $http->response([
            'id' => 'cs_test_123',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_123',
            'payment_status' => 'unpaid',
        ], 200),
    ]);

    $result = stripeGateway($http)->checkout(stripeCheckout());

    expect($result->id)->toBe('cs_test_123')
        ->and($result->status)->toBe(PaymentStatus::Pending)
        ->and($result->redirectUrl)->toBe('https://checkout.stripe.com/c/pay/cs_test_123');
});

it('sends an idempotency key derived from the reference', function (): void {
    $http = new HttpFactory;
    $http->fake([
        'api.stripe.com/v1/checkout/sessions' => $http->response(['id' => 'cs_1', 'url' => 'https://x.test'], 200),
    ]);

    stripeGateway($http)->checkout(stripeCheckout());

    $http->recorded(function ($request): bool {
        expect($request->hasHeader('Idempotency-Key', 'checkout_order_99'))->toBeTrue();

        return true;
    });
});

it('throws PaymentFailed when stripe rejects the checkout', function (): void {
    $http = new HttpFactory;
    $http->fake([
        'api.stripe.com/v1/checkout/sessions' => $http->response([
            'error' => ['message' => 'Invalid API Key provided'],
        ], 401),
    ]);

    stripeGateway($http)->checkout(stripeCheckout());
})->throws(PaymentFailed::class, 'Failed to create checkout session');

it('issues a refund', function (): void {
    $http = new HttpFactory;
    $http->fake([
        'api.stripe.com/v1/refunds' => $http->response([
            'id' => 're_test_456',
            'status' => 'succeeded',
        ], 200),
    ]);

    $result = stripeGateway($http)->refund('pi_test_123', 1000);

    expect($result->id)->toBe('re_test_456')
        ->and($result->status)->toBe(PaymentStatus::Refunded);
});

it('retrieves a checkout session and maps a paid status', function (): void {
    $http = new HttpFactory;
    $http->fake([
        'api.stripe.com/v1/checkout/sessions/cs_test_123' => $http->response([
            'id' => 'cs_test_123',
            'status' => 'complete',
            'payment_status' => 'paid',
        ], 200),
    ]);

    $result = stripeGateway($http)->retrieve('cs_test_123');

    expect($result->id)->toBe('cs_test_123')
        ->and($result->status)->toBe(PaymentStatus::Paid);
});

it('retrieves a payment intent by id', function (): void {
    $http = new HttpFactory;
    $http->fake([
        'api.stripe.com/v1/payment_intents/pi_test_9' => $http->response([
            'id' => 'pi_test_9',
            'status' => 'succeeded',
        ], 200),
    ]);

    $result = stripeGateway($http)->retrieve('pi_test_9');

    expect($result->id)->toBe('pi_test_9')
        ->and($result->status)->toBe(PaymentStatus::Paid);
});

it('verifies a valid webhook signature and normalizes the event', function (): void {
    $secret = 'whsec_test_x';
    $body = json_encode([
        'type' => 'checkout.session.completed',
        'data' => ['object' => ['id' => 'cs_test_123']],
    ]);
    $timestamp = time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

    $request = Request::create(
        '/payment-gateways/webhook/stripe',
        'POST',
        server: ['HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}"],
        content: $body,
    );

    $event = stripeGateway(new HttpFactory)->webhook($request);

    expect($event->gateway)->toBe('stripe')
        ->and($event->type)->toBe('payment.succeeded')
        ->and($event->paymentId)->toBe('cs_test_123');
});

it('exposes our checkout reference from a session webhook', function (): void {
    $secret = 'whsec_test_x';
    $body = json_encode([
        'type' => 'checkout.session.completed',
        'data' => ['object' => ['id' => 'cs_test_123', 'client_reference_id' => 'order_99']],
    ]);
    $timestamp = time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

    $request = Request::create(
        '/payment-gateways/webhook/stripe',
        'POST',
        server: ['HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}"],
        content: $body,
    );

    expect(stripeGateway(new HttpFactory)->webhook($request)->reference)->toBe('order_99');
});

it('exposes our reference from a payment_intent webhook via metadata', function (): void {
    $secret = 'whsec_test_x';
    $body = json_encode([
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => ['id' => 'pi_test_9', 'metadata' => ['reference' => 'order_99']]],
    ]);
    $timestamp = time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

    $request = Request::create(
        '/payment-gateways/webhook/stripe',
        'POST',
        server: ['HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}"],
        content: $body,
    );

    $event = stripeGateway(new HttpFactory)->webhook($request);

    expect($event->reference)->toBe('order_99')
        ->and($event->paymentId)->toBe('pi_test_9');
});

it('propagates the reference onto the payment intent at checkout', function (): void {
    $http = new HttpFactory;
    $http->fake([
        'api.stripe.com/v1/checkout/sessions' => $http->response(['id' => 'cs_1', 'url' => 'https://x.test'], 200),
    ]);

    stripeGateway($http)->checkout(stripeCheckout());

    $captured = null;
    $http->recorded(function ($request) use (&$captured): bool {
        $captured = $request->data();

        return true;
    });

    expect($captured['payment_intent_data[metadata][reference]'])->toBe('order_99');
});

it('exposes the paid amount and currency from a session webhook', function (): void {
    $secret = 'whsec_test_x';
    $body = json_encode([
        'type' => 'checkout.session.completed',
        'data' => ['object' => ['id' => 'cs_1', 'amount_total' => 2500, 'currency' => 'usd']],
    ]);
    $timestamp = time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

    $request = Request::create(
        '/payment-gateways/webhook/stripe',
        'POST',
        server: ['HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}"],
        content: $body,
    );

    $event = stripeGateway(new HttpFactory)->webhook($request);

    expect($event->amount)->toBe(2500)
        ->and($event->currency)->toBe('USD');
});

it('rejects a webhook with a bad signature', function (): void {
    $body = json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_x']]]);

    $request = Request::create(
        '/payment-gateways/webhook/stripe',
        'POST',
        server: ['HTTP_STRIPE_SIGNATURE' => 't='.time().',v1=deadbeef'],
        content: $body,
    );

    stripeGateway(new HttpFactory)->webhook($request);
})->throws(InvalidWebhookSignature::class);

it('rejects a webhook with a stale timestamp', function (): void {
    $secret = 'whsec_test_x';
    $body = json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_x']]]);
    $staleTimestamp = time() - 3600;
    $signature = hash_hmac('sha256', $staleTimestamp.'.'.$body, $secret);

    $request = Request::create(
        '/payment-gateways/webhook/stripe',
        'POST',
        server: ['HTTP_STRIPE_SIGNATURE' => "t={$staleTimestamp},v1={$signature}"],
        content: $body,
    );

    stripeGateway(new HttpFactory)->webhook($request);
})->throws(InvalidWebhookSignature::class);

it('maps stripe refund events to the canonical refunded type', function (): void {
    $secret = 'whsec_test_x';
    $body = json_encode(['type' => 'charge.refunded', 'data' => ['object' => ['id' => 'ch_test_1']]]);
    $timestamp = time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

    $request = Request::create(
        '/payment-gateways/webhook/stripe',
        'POST',
        server: ['HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}"],
        content: $body,
    );

    expect(stripeGateway(new HttpFactory)->webhook($request)->type)->toBe('payment.refunded');
});
