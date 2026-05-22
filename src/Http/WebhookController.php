<?php

declare(strict_types=1);

namespace Syriable\Payments\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Syriable\Payments\Data\WebhookEvent;
use Syriable\Payments\Exceptions\GatewayNotConfigured;
use Syriable\Payments\Exceptions\InvalidWebhookSignature;
use Syriable\Payments\GatewayManager;
use Syriable\Payments\Jobs\ProcessWebhookEvent;

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

        // Gateways legitimately redeliver; drop duplicates before dispatching.
        if ($this->alreadyProcessed($event)) {
            return new Response('OK', 200);
        }

        // Acknowledge fast; do the business processing off the request cycle.
        $connection = config('payment-gateways.webhook.connection');
        $queue = config('payment-gateways.webhook.queue');

        ProcessWebhookEvent::dispatch($event)
            ->onConnection(is_string($connection) ? $connection : null)
            ->onQueue(is_string($queue) ? $queue : null);

        return new Response('OK', 200);
    }

    /**
     * Returns true the second (and later) time the same event id is seen.
     * Cache::add is atomic, so concurrent redeliveries can't both win.
     */
    private function alreadyProcessed(WebhookEvent $event): bool
    {
        if ($event->eventId === null || $event->eventId === '') {
            return false;
        }

        $ttl = (int) config('payment-gateways.webhook.idempotency_ttl', 86400);
        $key = "payment-gateways:webhook:{$event->gateway}:{$event->eventId}";

        return ! Cache::add($key, true, $ttl);
    }
}
