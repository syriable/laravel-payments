<?php

declare(strict_types=1);

namespace Syriable\Payments\Contracts;

use Syriable\Payments\Data\PaymentResult;

/**
 * Implemented by gateways that support refunds.
 *
 * Consumers should check with `instanceof` before calling:
 *
 *     if ($gateway instanceof Refundable) {
 *         $gateway->refund($paymentId);
 *     }
 *
 * This is more type-safe than a runtime supportsRefunds(): bool method
 * and matches the idiom used by Laravel's ShouldQueue, ShouldBroadcast,
 * etc.
 */
interface Refundable
{
    /**
     * Refund a previous payment in full, or a partial amount in minor
     * units (e.g. cents/halalas) when $amount is provided.
     */
    public function refund(string $paymentId, ?int $amount = null): PaymentResult;
}
