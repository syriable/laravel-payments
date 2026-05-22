<?php

declare(strict_types=1);

namespace Syriable\Payments\Data;

use Syriable\Payments\Enums\PaymentStatus;

/**
 * A normalized payment result returned by any gateway.
 *
 * $reference is your own checkout reference, $amount (minor units) and
 * $currency the gateway-reported figures, when the gateway exposes them on
 * this call (e.g. retrieve()). Reconcile on $reference and re-check $amount
 * before fulfilling, exactly as you would for a webhook.
 *
 * $raw preserves the gateway's full untouched response for cases the
 * normalized fields don't cover (3DS challenges, partial captures,
 * gateway-specific metadata).
 */
final readonly class PaymentResult
{
    /**
     * @param  string  $id  The gateway's identifier for this payment (session id, intent id, etc.)
     * @param  PaymentStatus  $status  Normalized status
     * @param  string|null  $redirectUrl  Hosted-checkout redirect URL, if applicable
     * @param  string|null  $reference  Your own checkout reference, echoed back
     * @param  int|null  $amount  Gateway-reported amount in minor units
     * @param  string|null  $currency  Gateway-reported ISO 4217 currency
     * @param  array<array-key, mixed>  $raw  Full untouched gateway response
     */
    public function __construct(
        public string $id,
        public PaymentStatus $status,
        public ?string $redirectUrl = null,
        public ?string $reference = null,
        public ?int $amount = null,
        public ?string $currency = null,
        public array $raw = [],
    ) {}
}
