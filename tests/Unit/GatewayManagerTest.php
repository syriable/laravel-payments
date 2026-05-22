<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Syriable\Payments\Contracts\Capturable;
use Syriable\Payments\Contracts\Gateway;
use Syriable\Payments\Contracts\Refundable;
use Syriable\Payments\Data\Checkout;
use Syriable\Payments\Data\PaymentResult;
use Syriable\Payments\Data\WebhookEvent;
use Syriable\Payments\Enums\PaymentStatus;
use Syriable\Payments\Enums\WebhookEventType;
use Syriable\Payments\Exceptions\GatewayNotConfigured;
use Syriable\Payments\Facades\Gateway as GatewayFacade;
use Syriable\Payments\GatewayManager;
use Syriable\Payments\Gateways\PayPal\PayPalGateway;
use Syriable\Payments\Gateways\Stripe\StripeGateway;

it('resolves the built-in stripe driver', function (): void {
    $gateway = app(GatewayManager::class)->gateway('stripe');

    expect($gateway)->toBeInstanceOf(StripeGateway::class)
        ->and($gateway->name())->toBe('stripe');
});

it('resolves the built-in paypal driver', function (): void {
    $gateway = app(GatewayManager::class)->gateway('paypal');

    expect($gateway)->toBeInstanceOf(PayPalGateway::class)
        ->and($gateway->name())->toBe('paypal');
});

it('falls back to the configured default driver', function (): void {
    config()->set('payment-gateways.default', 'paypal');

    expect(app(GatewayManager::class)->gateway())->toBeInstanceOf(PayPalGateway::class);
});

it('throws when no default gateway is configured', function (): void {
    config()->set('payment-gateways.default', null);

    app(GatewayManager::class)->gateway();
})->throws(GatewayNotConfigured::class, 'No default payment gateway');

it('caches resolved driver instances', function (): void {
    $manager = app(GatewayManager::class);

    expect($manager->gateway('stripe'))->toBe($manager->gateway('stripe'));
});

it('registers a custom gateway through extend()', function (): void {
    $custom = new class implements Gateway
    {
        public function name(): string
        {
            return 'paymob';
        }

        public function checkout(Checkout $checkout): PaymentResult
        {
            return new PaymentResult(
                id: 'paymob_1',
                status: PaymentStatus::Pending,
            );
        }

        public function retrieve(string $paymentId): PaymentResult
        {
            return new PaymentResult($paymentId, PaymentStatus::Paid);
        }

        public function webhook(Request $request): WebhookEvent
        {
            return new WebhookEvent('paymob', WebhookEventType::Succeeded, 'paymob_1');
        }
    };

    GatewayFacade::extend('paymob', fn (): Gateway => $custom);

    expect(GatewayFacade::gateway('paymob'))->toBe($custom)
        ->and(GatewayFacade::gateway('paymob')->name())->toBe('paymob');
});

it('exposes refund capability through the type system', function (): void {
    expect(app(GatewayManager::class)->gateway('stripe'))->toBeInstanceOf(Refundable::class);
});

it('does not claim capabilities a gateway lacks', function (): void {
    $minimal = new class implements Gateway
    {
        public function name(): string
        {
            return 'cash';
        }

        public function checkout(Checkout $checkout): PaymentResult
        {
            return new PaymentResult('cash_1', PaymentStatus::Pending);
        }

        public function retrieve(string $paymentId): PaymentResult
        {
            return new PaymentResult($paymentId, PaymentStatus::Paid);
        }

        public function webhook(Request $request): WebhookEvent
        {
            return new WebhookEvent('cash', WebhookEventType::Succeeded, 'cash_1');
        }
    };

    GatewayFacade::extend('cash', fn (): Gateway => $minimal);

    expect(GatewayFacade::gateway('cash'))
        ->not->toBeInstanceOf(Refundable::class)
        ->not->toBeInstanceOf(Capturable::class);
});
