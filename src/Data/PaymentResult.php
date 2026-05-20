<?php

declare(strict_types=1);

namespace Syriable\Payments\Data;

use Syriable\Payments\Enums\PaymentStatus;

/**
 * A normalized payment result returned by any gateway.
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
     * @param  array<array-key, mixed>  $raw  Full untouched gateway response
     */
    public function __construct(
        public string $id,
        public PaymentStatus $status,
        public ?string $redirectUrl = null,
        public array $raw = [],
    ) {}
}
