<?php

declare(strict_types=1);

namespace Syriable\Payments\Data;

use Syriable\Payments\Enums\WebhookEventType;

/**
 * A normalized webhook event.
 *
 * $type is a WebhookEventType from a closed, cross-gateway vocabulary;
 * events without a canonical mapping arrive as WebhookEventType::Unknown
 * (the original gateway name is still in $payload).
 *
 * $gateway is the canonical driver name (e.g. "stripe"), useful for
 * listeners that handle multiple gateways.
 *
 * $reference is your own checkout reference echoed back by the gateway.
 * Reconcile on this, not $paymentId: the gateway's id can point at
 * different objects across events (a Stripe session id vs. a payment
 * intent id), but $reference is always the value you sent.
 *
 * $amount (minor units) and $currency are the gateway-reported figures for
 * the event. Always re-check them against your order before fulfilling —
 * confirming the amount paid is the canonical payment-consistency control.
 *
 * $eventId is the gateway's unique id for this delivery, used to drop
 * redelivered (duplicate) webhooks.
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
        public WebhookEventType $type,
        public string $paymentId,
        public array $payload = [],
        public ?string $reference = null,
        public ?int $amount = null,
        public ?string $currency = null,
        public ?string $eventId = null,
    ) {}
}
