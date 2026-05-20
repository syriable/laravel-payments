<?php

declare(strict_types=1);

namespace Syriable\Payments\Exceptions;

/**
 * Thrown when a webhook request fails signature verification.
 *
 * The webhook controller catches this specifically and returns 403,
 * separating "we couldn't trust this request" from "something else
 * went wrong while processing".
 */
final class InvalidWebhookSignature extends PaymentException
{
    public static function forGateway(string $gateway): self
    {
        return new self("Invalid webhook signature for gateway [{$gateway}].");
    }
}
