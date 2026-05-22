<?php

declare(strict_types=1);

namespace Syriable\Payments\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Syriable\Payments\Contracts\WebhookStore;
use Syriable\Payments\Data\WebhookEvent;
use Syriable\Payments\Exceptions\GatewayNotConfigured;
use Syriable\Payments\Exceptions\InvalidWebhookSignature;
use Syriable\Payments\GatewayManager;
use Syriable\Payments\Jobs\ProcessWebhookEvent;
use Syriable\Payments\Support\PaymentLog;

/**
 * The package's single webhook entrypoint.
 *
 * Route: POST /payment-gateways/webhook/{gateway}
 *
 * Flow:
 *   1. Resolve the named gateway driver via GatewayManager.
 *   2. Hand the request to the driver's webhook() method, which
 *      verifies the signature and returns a normalized WebhookEvent.
 *   3. Persist the verified event through the configured WebhookStore.
 *   4. Acknowledge the gateway immediately, then process the event from a
 *      queued job that dispatches one of the canonical events
 *      (PaymentSucceeded / PaymentFailed / PaymentRefunded).
 */
final class WebhookController
{
    public function __invoke(Request $request, string $gateway, GatewayManager $manager, WebhookStore $store): Response
    {
        try {
            $driver = $manager->gateway($gateway);
        } catch (GatewayNotConfigured) {
            return new Response('Unknown gateway', 404);
        }

        try {
            $event = $driver->webhook($request);
        } catch (InvalidWebhookSignature) {
            PaymentLog::warning('payments.webhook.invalid_signature', ['gateway' => $gateway]);

            return new Response('Invalid signature', 403);
        }

        // Persist before claiming the dedup key: if storage fails we 500
        // here, the key is never claimed, and the gateway's retry isn't lost.
        $storeId = $store->store($event);

        // Gateways legitimately redeliver; drop duplicates before dispatching.
        if ($this->alreadyProcessed($event)) {
            PaymentLog::info('payments.webhook.duplicate', [
                'gateway' => $event->gateway,
                'event_id' => $event->eventId,
            ]);

            return new Response('OK', 200);
        }

        PaymentLog::info('payments.webhook.received', [
            'gateway' => $event->gateway,
            'type' => $event->type->value,
            'event_id' => $event->eventId,
            'payment_id' => $event->paymentId,
            'reference' => $event->reference,
        ]);

        // Acknowledge fast; do the business processing off the request cycle.
        $connection = config('payment-gateways.webhook.connection');
        $queue = config('payment-gateways.webhook.queue');

        ProcessWebhookEvent::dispatch($event, $storeId)
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
