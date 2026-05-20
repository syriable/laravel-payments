<?php

declare(strict_types=1);

use Syriable\Payments\Contracts\Refundable;
use Syriable\Payments\Data\Checkout;
use Syriable\Payments\Enums\PaymentStatus;
use Syriable\Payments\Facades\Gateway;

function fakeCheckout(int $amount = 5000): Checkout
{
    return new Checkout(
        amount: $amount,
        currency: 'USD',
        reference: 'order_fake',
        successUrl: 'https://shop.test/s',
        cancelUrl: 'https://shop.test/c',
    );
}

it('swaps in a fake gateway and records checkouts', function (): void {
    $fake = Gateway::fake();

    $result = Gateway::driver('stripe')->checkout(fakeCheckout());

    expect($result->status)->toBe(PaymentStatus::Pending);

    $fake->assertCheckedOut();
    $fake->assertCheckoutCount(1, 'stripe');
});

it('asserts checkout matched a callback', function (): void {
    $fake = Gateway::fake();

    Gateway::driver('stripe')->checkout(fakeCheckout(7500));

    $fake->assertCheckedOut(
        static fn (Checkout $c): bool => $c->amount === 7500 && $c->currency === 'USD',
        'stripe',
    );
});

it('asserts nothing was charged when no checkout happened', function (): void {
    $fake = Gateway::fake();

    $fake->assertNothingCharged('stripe');
});

it('records refunds against the fake', function (): void {
    $fake = Gateway::fake();

    /** @var Refundable $gateway */
    $gateway = Gateway::driver('stripe');
    $gateway->refund('pi_fake_1', 2500);

    $fake->assertRefunded(
        /** @param array{paymentId: string, amount: int|null} $r */
        static fn (array $r): bool => $r['paymentId'] === 'pi_fake_1' && $r['amount'] === 2500,
        'stripe',
    );
});

it('keeps the facade passthrough working under fake', function (): void {
    $fake = Gateway::fake();

    // __call passthrough to the default driver still resolves to a fake.
    Gateway::checkout(fakeCheckout());

    $fake->assertCheckedOut(name: 'stripe');
});
