<?php

declare(strict_types=1);

namespace Syriable\Payments\Data;

use InvalidArgumentException;

/**
 * A checkout request, ready to be handed to a gateway.
 *
 * Financial-safety design notes:
 *  - $amount is in *minor units* (cents, halalas, kobo) as an int. Never
 *    a float. Floating-point math on money is forbidden by this package's
 *    public surface.
 *  - $currency is an ISO 4217 three-letter code, validated at construction.
 *  - $reference is opaque to the package and is your own order ID. Make it
 *    unique per checkout attempt to enable idempotent retries on the
 *    consumer side.
 *
 * Instances are deeply immutable and safe to pass through queues and events.
 */
final readonly class Checkout
{
    /**
     * @param  int  $amount  Amount in minor units (e.g. 2500 == $25.00)
     * @param  string  $currency  ISO 4217 alpha-3 currency code (e.g. "USD")
     * @param  string  $reference  Your unique order/checkout reference
     * @param  string  $successUrl  Absolute URL the user is sent to on success
     * @param  string  $cancelUrl  Absolute URL the user is sent to on cancellation
     * @param  string|null  $customerEmail  Customer email, if known
     * @param  array<string, scalar|null>  $metadata  Free-form metadata passed through to the gateway
     */
    public function __construct(
        public int $amount,
        public string $currency,
        public string $reference,
        public string $successUrl,
        public string $cancelUrl,
        public ?string $customerEmail = null,
        public array $metadata = [],
    ) {
        if ($this->amount <= 0) {
            throw new InvalidArgumentException(
                "Checkout amount must be a positive integer in minor units; got [{$this->amount}]."
            );
        }

        if (preg_match('/^[A-Z]{3}$/', $this->currency) !== 1) {
            throw new InvalidArgumentException(
                "Checkout currency must be an ISO 4217 alpha-3 code (e.g. 'USD'); got [{$this->currency}]."
            );
        }

        if ($this->reference === '') {
            throw new InvalidArgumentException('Checkout reference must not be empty.');
        }

        if (! filter_var($this->successUrl, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException("Checkout successUrl must be a valid absolute URL; got [{$this->successUrl}].");
        }

        if (! filter_var($this->cancelUrl, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException("Checkout cancelUrl must be a valid absolute URL; got [{$this->cancelUrl}].");
        }
    }
}
