<?php

declare(strict_types=1);

namespace Syriable\Payments\Exceptions;

use Throwable;

final class PaymentFailed extends PaymentException
{
    /**
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        string $message,
        public readonly string $gateway = '',
        public readonly array $raw = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @param  array<array-key, mixed>  $raw
     */
    public static function fromGateway(string $gateway, string $message, array $raw = []): self
    {
        return new self(
            message: "[{$gateway}] {$message}",
            gateway: $gateway,
            raw: $raw,
        );
    }
}
