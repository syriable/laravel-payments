<?php

declare(strict_types=1);

namespace Syriable\Payments\Contracts;

use Illuminate\Http\Request;
use Syriable\Payments\Data\Checkout;
use Syriable\Payments\Data\PaymentResult;
use Syriable\Payments\Data\WebhookEvent;

/**
 * The minimal contract every payment gateway must satisfy.
 *
 * Three responsibilities, nothing more:
 *  - initiate a checkout
 *  - parse and verify an incoming webhook
 *  - report its own canonical name (used by the manager + events)
 *
 * Refunds, captures, and other optional capabilities are expressed as
 * separate interfaces (see Refundable, Capturable) so that gateways
 * advertise capabilities through the type system, not through booleans.
 */
interface Gateway
{
    /**
     * The gateway's canonical name (e.g. "stripe", "paypal", "paymob").
     *
     * Must match the key used in config('payment-gateways.gateways.*')
     * and the driver name used by GatewayManager::driver().
     */
    public function name(): string;

    /**
     * Start a checkout. Returns a PaymentResult whose redirectUrl (when
     * present) should be returned to the user as a redirect response.
     */
    public function checkout(Checkout $checkout): PaymentResult;

    /**
     * Fetch the current state of a payment straight from the gateway.
     *
     * Webhooks are best-effort and can be lost; this is the authoritative
     * pull used to reconcile orders stuck in a non-final state.
     */
    public function retrieve(string $paymentId): PaymentResult;

    /**
     * Verify and parse an incoming webhook request.
     *
     * Implementations MUST verify the signature before parsing. On
     * signature failure, throw \Syriable\Payments\Exceptions\InvalidWebhookSignature.
     */
    public function webhook(Request $request): WebhookEvent;
}
