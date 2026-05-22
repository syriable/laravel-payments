<?php

declare(strict_types=1);

namespace Syriable\Payments\Data;

/**
 * A normalized webhook event.
 *
 * $type uses a stable, cross-gateway vocabulary:
 *   - "payment.succeeded"
 *   - "payment.failed"
 *   - "payment.refunded"
 *   - "payment.{anything-else}" passed through from the gateway when no
 *     canonical mapping exists
 *
 * $gateway is the canonical driver name (e.g. "stripe"), useful for
 * listeners that handle multiple gateways.
 *
 * $reference is your own checkout reference echoed back by the gateway.
 * Reconcile on this, not $paymentId: the gateway's id can point at
 * different objects across events (a Stripe session id vs. a payment
 * intent id), but $reference is always the value you sent.
 *
 * $payload is the full verified webhook body for cases that need the
 * detail.
 */
final readonly class WebhookEvent
{
    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function __construct(
        public string $gateway,
        public string $type,
        public string $paymentId,
        public array $payload = [],
        public ?string $reference = null,
    ) {}
}
