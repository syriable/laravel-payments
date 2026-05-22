<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use Syriable\Payments\Data\Checkout;
use Syriable\Payments\Gateways\Stripe\StripeGateway;

it('logs checkout creation', function (): void {
    Log::spy();

    $http = new HttpFactory;
    $http->fake([
        'api.stripe.com/v1/checkout/sessions' => $http->response(['id' => 'cs_1', 'url' => 'https://x.test'], 200),
    ]);

    (new StripeGateway(['secret' => 'sk', 'webhook_secret' => 'wh'], $http))->checkout(new Checkout(
        amount: 2500,
        currency: 'USD',
        reference: 'order_log_1',
        successUrl: 'https://shop.test/s',
        cancelUrl: 'https://shop.test/c',
    ));

    Log::shouldHaveReceived('info')->withArgs(
        fn (string $message, array $context = []): bool => $message === 'payments.checkout.created'
            && ($context['reference'] ?? null) === 'order_log_1',
    );
});

it('logs a received webhook', function (): void {
    Log::spy();

    $secret = 'whsec_test_dummy';
    $body = json_encode([
        'id' => 'evt_log_1',
        'type' => 'checkout.session.completed',
        'data' => ['object' => ['id' => 'cs_log_1']],
    ]);
    $timestamp = time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

    $this->call(
        'POST',
        '/payment-gateways/webhook/stripe',
        server: ['HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}", 'CONTENT_TYPE' => 'application/json'],
        content: $body,
    )->assertOk();

    Log::shouldHaveReceived('info')->withArgs(
        fn (string $message, array $context = []): bool => $message === 'payments.webhook.received'
            && ($context['event_id'] ?? null) === 'evt_log_1',
    );
});
