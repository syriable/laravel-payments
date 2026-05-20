<?php

declare(strict_types=1);

namespace Syriable\Payments\Exceptions;

use Throwable;

final class GatewayNotConfigured extends PaymentException
{
    public static function missingDefault(): self
    {
        return new self(
            'No default payment gateway is configured. Set "default" in config/payment-gateways.php '
                .'or call Gateway::driver(name) explicitly.'
        );
    }

    public static function unknownGateway(string $gateway, ?Throwable $previous = null): self
    {
        return new self(
            "Payment gateway [{$gateway}] is not registered. Add it to config/payment-gateways.php "
                .'or register it with Gateway::extend().',
            0,
            $previous,
        );
    }

    public static function missingCredentials(string $gateway, string $field): self
    {
        return new self(
            "Gateway [{$gateway}] is missing required configuration field [{$field}]."
        );
    }
}
