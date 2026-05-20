<?php

declare(strict_types=1);

namespace Syriable\Payments\Exceptions;

/**
 * Thrown when consumer code tries to use a capability (Refundable, Capturable)
 * that the resolved gateway doesn't implement.
 *
 * In practice the type-safe path is `instanceof Refundable` before calling
 * refund(), but this exception exists for the rare cases where the check
 * is centralized elsewhere.
 */
final class UnsupportedFeature extends PaymentException
{
    public static function notRefundable(string $gateway): self
    {
        return new self("Gateway [{$gateway}] does not support refunds.");
    }

    public static function notCapturable(string $gateway): self
    {
        return new self("Gateway [{$gateway}] does not support manual capture.");
    }
}
