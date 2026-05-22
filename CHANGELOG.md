# Changelog

All notable changes to `syriable/laravel-payments` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased](https://github.com/syriable/laravel-payments/compare/v1.0.0...HEAD)

## [1.0.0](https://github.com/syriable/laravel-payments/compare/v0.1.0...v1.0.0) - 2026-05-22

The first stable release — "trustworthy money": reconciliation, durable
webhook delivery, payment-consistency and webhook-integrity controls, and
typed events end to end. This consolidates the previously unreleased 0.1.1
work and is a breaking change from 0.1.0; see **Upgrading** below.

### Added

- Durable, swappable webhook persistence. Verified webhooks are now persisted
  through a `Contracts\WebhookStore` before async processing. The default
  `Store\DatabaseWebhookStore` records each delivery to a `payment_webhook_calls`
  table (publishable migration) with a `pending` → `processed`/`failed`
  lifecycle and is idempotent on the gateway event id. Swap in your own store
  via the `webhook.store` config key, or `Store\NullWebhookStore` to disable.
- `payment:reconcile {gateway} {payment}` artisan command and a
  `Jobs\ReconcilePayment` job — pull the authoritative state via `retrieve()`
  and re-emit the canonical payment event so existing webhook listeners run.
  Queued by default; `--sync` runs inline.
- `PaymentResult::$reference`, `$amount` (minor units), and `$currency`,
  populated by `retrieve()` on both drivers, so a reconciled payment can be
  verified with the same controls as a webhook.
- `Enums\WebhookCallStatus` (`Pending`, `Processed`, `Failed`).
- `Gateway::retrieve($paymentId)` on the gateway contract and both built-in
  drivers — pulls the authoritative payment state from the gateway so orders
  stuck in a non-final state can be reconciled when a webhook is missed.
- `WebhookEvent::$reference` — your own checkout reference echoed back by the
  gateway, for reliable reconciliation.
- `WebhookEvent::$amount` (minor units) and `WebhookEvent::$currency` — the
  gateway-reported figures for the event, so listeners can verify the amount
  paid matches the order before fulfilling. PayPal's major-unit decimals are
  normalized back to integer minor units.
- Outbound idempotency: checkout requests send an idempotency key derived from
  the reference (`Idempotency-Key` for Stripe, `PayPal-Request-Id` for PayPal)
  so a retried request can't create a duplicate session/order.
- Inbound idempotency: `WebhookEvent::$eventId` plus webhook-controller dedup
  (`webhook.idempotency_ttl` config) drops redelivered duplicates before
  dispatching events.
- Queued webhook processing: the controller acknowledges immediately and
  dispatches payment events from a `ProcessWebhookEvent` job, configurable via
  `webhook.connection` / `webhook.queue`, so slow listeners can't trigger
  gateway retries.
- Structured logging of the money-movement boundaries (checkout, refund, and
  webhook received/duplicate/invalid-signature) on a configurable channel
  (`logging.channel`). Only ids, references, and amounts are logged — never
  secrets or full payloads.
- Outbound HTTP resilience: gateway API calls now retry transient failures
  (connection errors, 429/5xx) with exponential backoff, configurable via the
  `http` config (`timeout`, `retry.times`, `retry.sleep_ms`). Retries are safe
  because checkouts carry idempotency keys; terminal errors (declines, bad
  requests) are not retried.

### Changed

- PayPal OAuth tokens are now cached (until just before `expires_in`) and
  reused across requests, instead of a fresh token round-trip on every call.
- `PaymentStatus` gained `RequiresAction`, `Processing`, `Canceled`, and
  `PartiallyRefunded` so SCA/3DS and cancellation flows are representable; the
  drivers now map these on `retrieve()`.
- `WebhookEvent::$type` is now a `WebhookEventType` enum (`Succeeded`,
  `Failed`, `Refunded`, `Unknown`) instead of a raw string, making the
  dispatch match exhaustive. Compare against the enum (the original gateway
  event name remains in `$payload`).

### Removed

- The `Capturable` contract (and `UnsupportedFeature::notCapturable`). It was
  implemented only by the test fake — no built-in gateway honored it and
  checkout always captures immediately — so it was a test/prod fidelity trap.
  Authorize-then-capture can return later as a complete feature.

### Fixed

- Stripe webhooks now carry the checkout reference on `payment_intent.*`
  events (not just `checkout.session.*`), so reconciling on the gateway's
  payment id no longer misses depending on which event the dashboard sends.
  Reconcile on `WebhookEvent::$reference`.

### Upgrading from 0.1.0

1. **Run the migration.** Webhook persistence is on by default. Publish and
   migrate:
   
   ```bash
   php artisan vendor:publish --tag="laravel-payments-migrations"
   php artisan migrate
   
   ```
   To keep the previous stateless behavior instead, set `webhook.store` to
   `Syriable\Payments\Store\NullWebhookStore::class`.
   
2. **`WebhookEvent::$type` is a `WebhookEventType` enum**, not a string.
   Compare against the enum; the raw gateway event name is still in `$payload`.
   
3. **`Gateway::retrieve()` is now required** by the `Gateway` contract. Custom
   gateways must implement it (return a `PaymentResult`).
   
4. **The `Capturable` contract was removed.** It was implemented only by the
   test fake. Use `UnsupportedFeature` for genuinely unsupported operations.
   
5. **`PaymentResult` gained `reference`/`amount`/`currency`** before `raw`.
   This is source-compatible if you construct it with named arguments (as the
   built-in drivers do); positional callers that passed `raw` should switch to
   named arguments.
   

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

## [v0.1.1](https://github.com/syriable/laravel-payments/compare/v0.1.1...v0.1.1) - 2026-05-22

### What's Changed

* busy dirac 7 vto1 by @alkhatibsy in https://github.com/syriable/laravel-payments/pull/1

### New Contributors

* @alkhatibsy made their first contribution in https://github.com/syriable/laravel-payments/pull/1

**Full Changelog**: https://github.com/syriable/laravel-payments/compare/v0.1.0...v0.1.1

## [v1.0.0](https://github.com/syriable/laravel-payments/compare/v1.0.0...v1.0.0) - 2026-05-22

### What's Changed

* v1.0.0: reconciliation, durable webhook delivery, typed events by @alkhatibsy in https://github.com/syriable/laravel-payments/pull/2

**Full Changelog**: https://github.com/syriable/laravel-payments/compare/v0.1.1...v1.0.0
