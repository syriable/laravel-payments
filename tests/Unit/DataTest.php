<?php

declare(strict_types=1);

use Syriable\Payments\Data\Checkout;
use Syriable\Payments\Data\PaymentResult;
use Syriable\Payments\Enums\PaymentStatus;

it('accepts a valid checkout', function (): void {
    $checkout = new Checkout(
        amount: 2500,
        currency: 'USD',
        reference: 'order_42',
        successUrl: 'https://example.test/success',
        cancelUrl: 'https://example.test/cancel',
    );

    expect($checkout->amount)->toBe(2500)
        ->and($checkout->currency)->toBe('USD')
        ->and($checkout->reference)->toBe('order_42');
});

it('rejects zero or negative amounts', function (int $amount): void {
    new Checkout(
        amount: $amount,
        currency: 'USD',
        reference: 'order_x',
        successUrl: 'https://example.test/s',
        cancelUrl: 'https://example.test/c',
    );
})
    ->with([0, -1, -2500])
    ->throws(InvalidArgumentException::class, 'positive integer');

it('rejects malformed currency codes', function (string $currency): void {
    new Checkout(
        amount: 100,
        currency: $currency,
        reference: 'order_x',
        successUrl: 'https://example.test/s',
        cancelUrl: 'https://example.test/c',
    );
})
    ->with(['usd', 'US', 'USDD', '', '123'])
    ->throws(InvalidArgumentException::class, 'ISO 4217');

it('rejects empty references', function (): void {
    new Checkout(
        amount: 100,
        currency: 'USD',
        reference: '',
        successUrl: 'https://example.test/s',
        cancelUrl: 'https://example.test/c',
    );
})->throws(InvalidArgumentException::class, 'reference');

it('rejects non-URL success/cancel URLs', function (): void {
    new Checkout(
        amount: 100,
        currency: 'USD',
        reference: 'order_x',
        successUrl: 'not-a-url',
        cancelUrl: 'https://example.test/c',
    );
})->throws(InvalidArgumentException::class, 'successUrl');

it('produces an immutable PaymentResult', function (): void {
    $result = new PaymentResult(
        id: 'pi_test_123',
        status: PaymentStatus::Paid,
        redirectUrl: null,
        raw: ['foo' => 'bar'],
    );

    expect($result->id)->toBe('pi_test_123')
        ->and($result->status)->toBe(PaymentStatus::Paid)
        ->and($result->raw)->toBe(['foo' => 'bar']);
});
