<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Syriable\Payments\Data\Checkout;
use Syriable\Payments\Enums\PaymentStatus;
use Syriable\Payments\Enums\WebhookEventType;
use Syriable\Payments\Exceptions\InvalidWebhookSignature;
use Syriable\Payments\Gateways\PayPal\PayPalGateway;

function paypalConfig(): array
{
    return [
        'mode' => 'sandbox',
        'client_id' => 'cid',
        'client_secret' => 'secret',
        'webhook_id' => 'wh_test_1',
        'default_currency' => 'USD',
    ];
}

function paypalCheckout(int $amount = 2500, string $currency = 'USD'): Checkout
{
    return new Checkout(
        amount: $amount,
        currency: $currency,
        reference: 'order_pp_1',
        successUrl: 'https://shop.test/success',
        cancelUrl: 'https://shop.test/cancel',
    );
}

it('creates an order and returns the approve link', function (): void {
    $http = new HttpFactory;
    $http->fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => $http->response([
            'access_token' => 'A21AA-test-token',
            'token_type' => 'Bearer',
        ], 200),
        'api-m.sandbox.paypal.com/v2/checkout/orders' => $http->response([
            'id' => 'ORDER-123',
            'status' => 'CREATED',
            'links' => [
                ['rel' => 'self', 'href' => 'https://api-m.sandbox.paypal.com/v2/checkout/orders/ORDER-123'],
                ['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-123'],
            ],
        ], 201),
    ]);

    $result = (new PayPalGateway(paypalConfig(), $http))->checkout(paypalCheckout());

    expect($result->id)->toBe('ORDER-123')
        ->and($result->status)->toBe(PaymentStatus::Pending)
        ->and($result->redirectUrl)->toBe('https://www.sandbox.paypal.com/checkoutnow?token=ORDER-123');
});

it('converts minor units to the major-unit decimal string paypal expects', function (int $amount, string $currency, string $expected): void {
    $http = new HttpFactory;
    $captured = null;

    $http->fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => $http->response(['access_token' => 't'], 200),
        'api-m.sandbox.paypal.com/v2/checkout/orders' => $http->response(['id' => 'O-1', 'links' => []], 201),
    ]);

    (new PayPalGateway(paypalConfig(), $http))->checkout(paypalCheckout($amount, $currency));

    $http->recorded(function ($request) use (&$captured): bool {
        if (str_contains($request->url(), '/v2/checkout/orders')) {
            $captured = $request->data();
        }

        return true;
    });

    expect($captured['purchase_units'][0]['amount']['value'])->toBe($expected);
})->with([
    'usd cents' => [2500, 'USD', '25.00'],
    'usd with fraction' => [2599, 'USD', '25.99'],
    'jpy zero-decimal' => [4000, 'JPY', '4000'],
    'kwd three-decimal' => [12345, 'KWD', '12.345'],
]);

it('sends a paypal request id derived from the reference', function (): void {
    $http = new HttpFactory;
    $http->fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => $http->response(['access_token' => 't'], 200),
        'api-m.sandbox.paypal.com/v2/checkout/orders' => $http->response(['id' => 'O-1', 'links' => []], 201),
    ]);

    (new PayPalGateway(paypalConfig(), $http))->checkout(paypalCheckout());

    $http->recorded(function ($request): bool {
        if (str_contains($request->url(), '/v2/checkout/orders')) {
            expect($request->hasHeader('PayPal-Request-Id', 'checkout_order_pp_1'))->toBeTrue();
        }

        return true;
    });
});

it('issues a refund against a capture id', function (): void {
    $http = new HttpFactory;
    $http->fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => $http->response(['access_token' => 't'], 200),
        'api-m.sandbox.paypal.com/v2/payments/captures/*/refund' => $http->response([
            'id' => 'REFUND-1',
            'status' => 'COMPLETED',
        ], 201),
    ]);

    $result = (new PayPalGateway(paypalConfig(), $http))->refund('CAPTURE-9');

    expect($result->id)->toBe('REFUND-1')
        ->and($result->status)->toBe(PaymentStatus::Refunded);
});

it('retrieves an order and maps a completed status to paid', function (): void {
    $http = new HttpFactory;
    $http->fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => $http->response(['access_token' => 't'], 200),
        'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER-123' => $http->response([
            'id' => 'ORDER-123',
            'status' => 'COMPLETED',
        ], 200),
    ]);

    $result = (new PayPalGateway(paypalConfig(), $http))->retrieve('ORDER-123');

    expect($result->id)->toBe('ORDER-123')
        ->and($result->status)->toBe(PaymentStatus::Paid);
});

it('verifies a webhook by calling paypal verification endpoint', function (): void {
    $http = new HttpFactory;
    $http->fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => $http->response(['access_token' => 't'], 200),
        'api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => $http->response([
            'verification_status' => 'SUCCESS',
        ], 200),
    ]);

    $body = json_encode([
        'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
        'resource' => ['id' => 'CAPTURE-9'],
    ]);

    $request = Request::create(
        '/payment-gateways/webhook/paypal',
        'POST',
        server: [
            'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
            'HTTP_PAYPAL_CERT_URL' => 'https://api.sandbox.paypal.com/cert',
            'HTTP_PAYPAL_TRANSMISSION_ID' => 'tx-1',
            'HTTP_PAYPAL_TRANSMISSION_SIG' => 'sig-1',
            'HTTP_PAYPAL_TRANSMISSION_TIME' => '2026-05-20T00:00:00Z',
            'CONTENT_TYPE' => 'application/json',
        ],
        content: $body,
    );

    $event = (new PayPalGateway(paypalConfig(), $http))->webhook($request);

    expect($event->gateway)->toBe('paypal')
        ->and($event->type)->toBe(WebhookEventType::Succeeded)
        ->and($event->paymentId)->toBe('CAPTURE-9');
});

it('normalizes the webhook amount back to minor units', function (int|string $value, string $currency, int $expected): void {
    $http = new HttpFactory;
    $http->fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => $http->response(['access_token' => 't'], 200),
        'api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => $http->response([
            'verification_status' => 'SUCCESS',
        ], 200),
    ]);

    $request = Request::create(
        '/payment-gateways/webhook/paypal',
        'POST',
        server: [
            'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
            'HTTP_PAYPAL_CERT_URL' => 'https://api.sandbox.paypal.com/cert',
            'HTTP_PAYPAL_TRANSMISSION_ID' => 'tx-1',
            'HTTP_PAYPAL_TRANSMISSION_SIG' => 'sig-1',
            'HTTP_PAYPAL_TRANSMISSION_TIME' => '2026-05-20T00:00:00Z',
            'CONTENT_TYPE' => 'application/json',
        ],
        content: json_encode([
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => ['id' => 'C-1', 'amount' => ['currency_code' => $currency, 'value' => (string) $value]],
        ]),
    );

    $event = (new PayPalGateway(paypalConfig(), $http))->webhook($request);

    expect($event->amount)->toBe($expected)
        ->and($event->currency)->toBe($currency);
})->with([
    'usd' => ['25.00', 'USD', 2500],
    'usd fraction' => ['25.99', 'USD', 2599],
    'jpy zero-decimal' => ['4000', 'JPY', 4000],
    'kwd three-decimal' => ['12.345', 'KWD', 12345],
]);

it('exposes our checkout reference from a capture webhook custom_id', function (): void {
    $http = new HttpFactory;
    $http->fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => $http->response(['access_token' => 't'], 200),
        'api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => $http->response([
            'verification_status' => 'SUCCESS',
        ], 200),
    ]);

    $request = Request::create(
        '/payment-gateways/webhook/paypal',
        'POST',
        server: [
            'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
            'HTTP_PAYPAL_CERT_URL' => 'https://api.sandbox.paypal.com/cert',
            'HTTP_PAYPAL_TRANSMISSION_ID' => 'tx-1',
            'HTTP_PAYPAL_TRANSMISSION_SIG' => 'sig-1',
            'HTTP_PAYPAL_TRANSMISSION_TIME' => '2026-05-20T00:00:00Z',
            'CONTENT_TYPE' => 'application/json',
        ],
        content: json_encode([
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => ['id' => 'CAPTURE-9', 'custom_id' => 'order_pp_1'],
        ]),
    );

    expect((new PayPalGateway(paypalConfig(), $http))->webhook($request)->reference)->toBe('order_pp_1');
});

it('rejects a webhook paypal reports as unverified', function (): void {
    $http = new HttpFactory;
    $http->fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => $http->response(['access_token' => 't'], 200),
        'api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => $http->response([
            'verification_status' => 'FAILURE',
        ], 200),
    ]);

    $request = Request::create(
        '/payment-gateways/webhook/paypal',
        'POST',
        server: [
            'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
            'HTTP_PAYPAL_CERT_URL' => 'https://api.sandbox.paypal.com/cert',
            'HTTP_PAYPAL_TRANSMISSION_ID' => 'tx-1',
            'HTTP_PAYPAL_TRANSMISSION_SIG' => 'sig-1',
            'HTTP_PAYPAL_TRANSMISSION_TIME' => '2026-05-20T00:00:00Z',
            'CONTENT_TYPE' => 'application/json',
        ],
        content: json_encode(['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => ['id' => 'C-1']]),
    );

    (new PayPalGateway(paypalConfig(), $http))->webhook($request);
})->throws(InvalidWebhookSignature::class);

it('rejects a webhook missing required paypal headers', function (): void {
    $http = new HttpFactory;
    $http->fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => $http->response(['access_token' => 't'], 200),
    ]);

    $request = Request::create(
        '/payment-gateways/webhook/paypal',
        'POST',
        server: ['CONTENT_TYPE' => 'application/json'],
        content: json_encode(['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => ['id' => 'C-1']]),
    );

    (new PayPalGateway(paypalConfig(), $http))->webhook($request);
})->throws(InvalidWebhookSignature::class);
