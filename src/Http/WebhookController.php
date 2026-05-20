<?php

declare(strict_types=1);

namespace Syriable\Payments\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Syriable\Payments\Events\PaymentFailed;
use Syriable\Payments\Events\PaymentRefunded;
use Syriable\Payments\Events\PaymentSucceeded;
use Syriable\Payments\Exceptions\GatewayNotConfigured;
use Syriable\Payments\Exceptions\InvalidWebhookSignature;
use Syriable\Payments\GatewayManager;

/**
 * The package's single webhook entrypoint.
 *
 * Route: POST /payment-gateways/webhook/{gateway}
 *
 * Flow:
 *   1. Resolve the named gateway driver via GatewayManager.
 *   2. Hand the request to the driver's webhook() method, which
 *      verifies the signature and returns a normalized WebhookEvent.
 *   3. Dispatch one of the canonical events (PaymentSucceeded /
 *      PaymentFailed / PaymentRefunded). Consumers listen to these
 *      in their own application code.
 *
 * The package never touches the database. Storage is the consumer's job.
 */
final class WebhookController
{
    public function __invoke(Request $request, string $gateway, GatewayManager $manager): Response
    {
        try {
            $driver = $manager->gateway($gateway);
        } catch (GatewayNotConfigured) {
            return new Response('Unknown gateway', 404);
        }

        try {
            $event = $driver->webhook($request);
        } catch (InvalidWebhookSignature) {
            return new Response('Invalid signature', 403);
        }

        match (true) {
            str_ends_with($event->type, '.succeeded') => PaymentSucceeded::dispatch($event),
            str_ends_with($event->type, '.failed') => PaymentFailed::dispatch($event),
            str_ends_with($event->type, '.refunded') => PaymentRefunded::dispatch($event),
            default => null,
        };

        return new Response('OK', 200);
    }
}
