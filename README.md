<p align="center">
    <picture>
        <source media="(prefers-color-scheme: dark)" srcset="art/logo-dark.svg">
        <source media="(prefers-color-scheme: light)" srcset="art/logo-light.svg">
        <img alt="Syriable Payments" src="art/logo-light.svg" width="420">
    </picture>
</p>

<p align="center">
    <a href="https://packagist.org/packages/syriable/laravel-payments"><img src="https://img.shields.io/packagist/v/syriable/laravel-payments.svg?style=flat-square" alt="Latest Version on Packagist"></a>
    <a href="https://github.com/syriable/laravel-payments/actions/workflows/tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/syriable/laravel-payments/tests.yml?branch=main&label=tests&style=flat-square" alt="Tests"></a>
    <a href="https://packagist.org/packages/syriable/laravel-payments"><img src="https://img.shields.io/packagist/dt/syriable/laravel-payments.svg?style=flat-square" alt="Total Downloads"></a>
    <a href="https://packagist.org/packages/syriable/laravel-payments"><img src="https://img.shields.io/packagist/php-v/syriable/laravel-payments.svg?style=flat-square" alt="PHP Version"></a>
    <a href="LICENSE.md"><img src="https://img.shields.io/packagist/l/syriable/laravel-payments.svg?style=flat-square" alt="License"></a>
</p>

# Laravel Payments

A lightweight Laravel package for accepting payments through **Stripe**, **PayPal**, and an open ecosystem of community gateway plugins.

It does one thing well: it unifies payment gateways behind a small, Laravel-native API. It is **not** an accounting system, a subscription engine, or a billing platform — it never touches your database, and it never forces a schema on you.

```php
use Syriable\Payments\Data\Checkout;
use Syriable\Payments\Facades\Gateway;

$result = Gateway::driver('stripe')->checkout(new Checkout(
    amount: 2500,            // minor units — 2500 == $25.00
    currency: 'USD',
    reference: "order_{$order->id}",
    successUrl: route('orders.success', $order),
    cancelUrl: route('orders.cancel', $order),
));

return redirect($result->redirectUrl);
```

## Why this package

- **Tiny core.** A manager, a contract, three DTOs, two gateways. You can read the whole thing in an afternoon.
- **Laravel-native.** Built on `Illuminate\Support\Manager` — the same pattern behind `Cache`, `Mail`, and `Storage`. Nothing new to learn.
- **Financially safe by design.** Amounts are integer minor units. No floating-point math touches money, anywhere.
- **No hard SDK dependencies.** Gateways use Laravel's HTTP client. Your `vendor/` directory stays lean.
- **Plugin-friendly.** Add a gateway in ~20 lines, in your own Composer package, shipped on your own schedule.

## Requirements

- PHP 8.3+
- Laravel 12 or 13

## Installation

```bash
composer require syriable/laravel-payments
```

The service provider and the `Gateway` facade are auto-discovered. Publish the config:

```bash
php artisan vendor:publish --tag="laravel-payments-config"
```

Then set your credentials in `.env`:

```env
PAYMENT_GATEWAY=stripe

STRIPE_SECRET=sk_live_xxx
STRIPE_WEBHOOK_SECRET=whsec_xxx

PAYPAL_MODE=live
PAYPAL_CLIENT_ID=xxx
PAYPAL_CLIENT_SECRET=xxx
PAYPAL_WEBHOOK_ID=xxx
```

`STRIPE_WEBHOOK_SECRET` and `PAYPAL_WEBHOOK_ID` come from each provider's
dashboard when you register your webhook endpoint (see [Webhooks](#webhooks)).
Until they are set, incoming webhooks fail signature verification and return
`403` — so configure them before going live.

## Usage

### Checkout

```php
use Syriable\Payments\Data\Checkout;
use Syriable\Payments\Facades\Gateway;

$checkout = new Checkout(
    amount: 2500,
    currency: 'USD',
    reference: 'order_42',
    successUrl: 'https://shop.test/success',
    cancelUrl: 'https://shop.test/cancel',
    customerEmail: 'buyer@example.com',   // optional
    metadata: ['order_id' => 42],          // optional
);

// Explicit gateway:
$result = Gateway::driver('stripe')->checkout($checkout);

// Or the default gateway from config — __call passthrough handles this:
$result = Gateway::checkout($checkout);

return redirect($result->redirectUrl);
```

`checkout()` returns a `PaymentResult`:

```php
$result->id;            // gateway's payment/session id
$result->status;        // PaymentStatus enum: Pending | Paid | Failed | Refunded
$result->redirectUrl;   // hosted-checkout URL, if any
$result->raw;           // full untouched gateway response
```

> **Amounts are always integer minor units.** `2500` means `$25.00`,
> `100` means `$1.00`. Never pass a float or a major-unit value like `25.00` —
> `Checkout` rejects non-positive amounts at construction, but it cannot tell
> `25` cents from `25` dollars. Keeping money as integers is what makes the
> package financially safe; no floating-point math touches an amount anywhere.

> **Store `$result->id` on your order.** This is the gateway's identifier for
> the payment, and it is how the webhook later reconciles back to your order
> (see [Webhooks](#webhooks)). A typical checkout persists it immediately:
>
> ```php
> $order->update([
>     'gateway'            => 'stripe',
>     'gateway_payment_id' => $result->id,
> ]);
> ```

### Refunds

Refunds are an opt-in capability. Check for it with `instanceof` — the type system tells you whether a gateway supports it:

```php
use Syriable\Payments\Contracts\Refundable;

$gateway = Gateway::driver('stripe');

if ($gateway instanceof Refundable) {
    $gateway->refund($paymentId);          // full refund
    $gateway->refund($paymentId, 1000);    // partial — 1000 minor units
}
```

### Webhooks

The package registers one webhook route automatically:

```
POST /payment-gateways/webhook/{gateway}
```

Register that URL in each provider's dashboard. The controller verifies the
request's signature, then dispatches one of three events. You listen for them
in your own application:

```php
use Syriable\Payments\Events\PaymentSucceeded;

Event::listen(PaymentSucceeded::class, function (PaymentSucceeded $event) {
    // $event->event is a normalized WebhookEvent.
    // $event->event->paymentId is the *gateway's* id — the same value you
    // stored as gateway_payment_id when the checkout was created.
    Order::where('gateway', $event->event->gateway)
        ->where('gateway_payment_id', $event->event->paymentId)
        ->first()
        ?->markPaid();
});
```

Events: `PaymentSucceeded`, `PaymentFailed`, `PaymentRefunded`. Each carries a
normalized `WebhookEvent` with `$gateway`, `$type`, `$paymentId`, and the full
verified `$payload`.

Invalid signatures return `403` and dispatch nothing. Unknown gateways return
`404`.

> **Make your listener idempotent.** Gateways legitimately deliver the same
> webhook more than once. Guard against double-processing — e.g. skip the
> handler if the order is already marked paid.

> **Keep the webhook route off the `web` middleware group.** `web` enables
> CSRF protection, which rejects server-to-server webhook requests. The
> default config uses `api`; change `webhook.middleware` only to something
> equally CSRF-free.

## Adding a custom gateway

Two ways. For a one-off, register it in `AppServiceProvider::boot()`:

```php
use Syriable\Payments\Facades\Gateway;

Gateway::extend('paymob', fn ($app) => new \App\Payments\PaymobGateway(
    config('payment-gateways.gateways.paymob')
));
```

For something reusable, ship it as its own Composer package. A gateway plugin is just a package whose service provider calls `Gateway::extend()`:

```php
namespace Vendor\LaravelPaymentsPaymob;

use Illuminate\Support\ServiceProvider;
use Syriable\Payments\Facades\Gateway;

class PaymobServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gateway::extend('paymob', fn ($app) => new PaymobGateway(
            config('payment-gateways.gateways.paymob', [])
        ));
    }
}
```

Your gateway class implements the `Gateway` contract — and, optionally, `Refundable` and/or `Capturable`:

```php
use Syriable\Payments\Contracts\Gateway;
use Syriable\Payments\Contracts\Refundable;

final class PaymobGateway implements Gateway, Refundable
{
    public function name(): string { /* ... */ }
    public function checkout(Checkout $checkout): PaymentResult { /* ... */ }
    public function webhook(Request $request): WebhookEvent { /* ... */ }
    public function refund(string $paymentId, ?int $amount = null): PaymentResult { /* ... */ }
}
```

That's the entire plugin API. No plugin interface, no registry, no manifest.

## Testing

Swap in a fake gateway with one call. No HTTP, no real charges:

```php
use Syriable\Payments\Facades\Gateway;

it('checks the customer out', function () {
    $fake = Gateway::fake();

    $this->post('/checkout', ['order' => 42]);

    $fake->assertCheckedOut(fn ($checkout) => $checkout->amount === 2500);
});
```

Available assertions: `assertCheckedOut()`, `assertRefunded()`, `assertNothingCharged()`, `assertCheckoutCount()`.

## Configuration

The published `config/payment-gateways.php` is intentionally small:

```php
return [
    'default' => env('PAYMENT_GATEWAY', 'stripe'),

    'webhook' => [
        'enabled'    => true,
        'prefix'     => 'payment-gateways',
        'middleware' => ['api'],
    ],

    'gateways' => [
        'stripe' => [
            'secret'         => env('STRIPE_SECRET'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        ],
        'paypal' => [
            'mode'          => env('PAYPAL_MODE', 'sandbox'),
            'client_id'     => env('PAYPAL_CLIENT_ID'),
            'client_secret' => env('PAYPAL_CLIENT_SECRET'),
            'webhook_id'    => env('PAYPAL_WEBHOOK_ID'),
        ],
    ],
];
```

## Architecture at a glance

```
src/
├── Contracts/      Gateway, Refundable, Capturable
├── Data/           Checkout, PaymentResult, WebhookEvent  (readonly DTOs)
├── Enums/          PaymentStatus
├── Events/         PaymentSucceeded, PaymentFailed, PaymentRefunded
├── Exceptions/     PaymentException + 4 specific subclasses
├── Gateways/
│   ├── Stripe/     StripeGateway
│   └── PayPal/     PayPalGateway
├── Http/           WebhookController
├── Testing/        FakeGateway, FakeGatewayManager
├── Facades/        Gateway
├── GatewayManager.php
└── PaymentsServiceProvider.php
```

## Running the package test suite

```bash
composer test
```

Static analysis and code style:

```bash
composer analyse   # PHPStan / Larastan
composer format    # Laravel Pint
```

## Changelog

Please see [CHANGELOG.md](CHANGELOG.md) for details on what has changed recently.

## Security

If you discover a security vulnerability, please email **security@syriable.dev**
rather than using the issue tracker.

Webhook handlers verify signatures before parsing — Stripe via HMAC-SHA256,
PayPal via its verification API. A failed verification returns `403` and
dispatches no events.

## Credits

- [Syriable](https://github.com/syriable)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
