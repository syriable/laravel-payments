<?php

declare(strict_types=1);

namespace Syriable\Payments\Contracts;

use Syriable\Payments\Data\PaymentResult;

/**
 * Implemented by gateways that support a two-step authorize-then-capture
 * flow (as opposed to immediate capture at checkout time).
 *
 * Most gateways auto-capture; only implement this if the gateway exposes
 * an explicit capture step and your consumers actually need it.
 */
interface Capturable
{
    /**
     * Capture a previously authorized payment. Pass $amount in minor units
     * to capture less than the full authorized amount (where supported).
     */
    public function capture(string $paymentId, ?int $amount = null): PaymentResult;
}
