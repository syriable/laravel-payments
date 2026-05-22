# Changelog

All notable changes to `syriable/laravel-payments` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased](https://github.com/syriable/laravel-payments/compare/v0.1.0...HEAD)

### Added

- `Gateway::retrieve($paymentId)` on the gateway contract and both built-in
  drivers — pulls the authoritative payment state from the gateway so orders
  stuck in a non-final state can be reconciled when a webhook is missed.
- `WebhookEvent::$reference` — your own checkout reference echoed back by the
  gateway, for reliable reconciliation.

### Fixed

- Stripe webhooks now carry the checkout reference on `payment_intent.*`
  events (not just `checkout.session.*`), so reconciling on the gateway's
  payment id no longer misses depending on which event the dashboard sends.
  Reconcile on `WebhookEvent::$reference`.

## [0.1.0](https://github.com/syriable/laravel-payments/releases/tag/v0.1.0) - 2026-05-20

First public release. The `0.x` series is functional and tested, but the
public API may still change before `1.0.0` in response to real-world use.

### Added

- `GatewayManager` built on `Illuminate\Support\Manager` — driver resolution,
  instance caching, `extend()`, and `__call` passthrough to the default driver.
- `Gateway` facade with typed `@method` annotations for full IDE autocomplete.
- `Gateway` contract (`name`, `checkout`, `webhook`) plus opt-in `Refundable`
  and `Capturable` capability interfaces — capabilities are advertised through
  the type system, not runtime booleans.
- Readonly, immutable DTOs: `Checkout`, `PaymentResult`, `WebhookEvent`.
  `Checkout` validates its amount, currency, reference, and URLs at
  construction. Amounts are integer minor units; no floating-point math
  touches money anywhere in the package.
- `PaymentStatus` enum (`Pending`, `Paid`, `Failed`, `Refunded`).
- Built-in `StripeGateway` and `PayPalGateway` drivers, both built on
  Laravel's HTTP client with no hard SDK dependency. Stripe webhooks are
  verified via HMAC-SHA256; PayPal webhooks via PayPal's verification API.
- Single webhook entrypoint (`POST /payment-gateways/webhook/{gateway}`) that
  verifies signatures, then dispatches `PaymentSucceeded`, `PaymentFailed`, or
  `PaymentRefunded`. Invalid signatures return `403`; unknown gateways `404`.
- `Gateway::fake()` testing helper with `FakeGateway` / `FakeGatewayManager`
  and the assertions `assertCheckedOut()`, `assertRefunded()`,
  `assertNothingCharged()`, and `assertCheckoutCount()`.
- Exception hierarchy rooted at `PaymentException`, with `GatewayNotConfigured`,
  `PaymentFailed`, `InvalidWebhookSignature`, and `UnsupportedFeature`.
  Resolving an unregistered gateway raises `GatewayNotConfigured` (translated
  from the framework's internal `InvalidArgumentException`).
- Custom gateways register via `Gateway::extend()`; no plugin interface,
  registry, or manifest is required.
- Minimal, publishable config file (`config/payment-gateways.php`).

### Requirements

- PHP 8.3+
- Laravel 12 or 13

### Notes

- The package ships no migrations and no Eloquent models. It never touches
  your database; persisting payments is the consuming application's concern.

## [v0.1.0](https://github.com/syriable/laravel-payments/compare/v0.1.0...v0.1.0) - 2026-05-20

**Full Changelog**: https://github.com/syriable/laravel-payments/commits/v0.1.0
