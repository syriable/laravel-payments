<?php

declare(strict_types=1);

namespace Syriable\Payments\Store;

use Syriable\Payments\Contracts\WebhookStore;
use Syriable\Payments\Data\WebhookEvent;
use Syriable\Payments\Enums\WebhookCallStatus;
use Syriable\Payments\Models\WebhookCall;

/**
 * Persists webhooks to the payment_webhook_calls table. The default store.
 */
final class DatabaseWebhookStore implements WebhookStore
{
    public function store(WebhookEvent $event): string
    {
        $attributes = [
            'type' => $event->type->value,
            'payment_id' => $event->paymentId,
            'reference' => $event->reference,
            'amount' => $event->amount,
            'currency' => $event->currency,
            'payload' => $event->payload,
            'status' => WebhookCallStatus::Pending,
        ];

        // Idempotent: a redelivered event id reuses its row instead of
        // inserting a duplicate (the unique index enforces this under races).
        $call = $event->eventId !== null && $event->eventId !== ''
            ? WebhookCall::firstOrCreate(
                ['gateway' => $event->gateway, 'event_id' => $event->eventId],
                $attributes,
            )
            : WebhookCall::create(['gateway' => $event->gateway, 'event_id' => null] + $attributes);

        return (string) $call->getKey();
    }

    public function markProcessed(string $id): void
    {
        WebhookCall::query()->whereKey($id)->update([
            'status' => WebhookCallStatus::Processed->value,
            'processed_at' => now(),
            'exception' => null,
        ]);
    }

    public function markFailed(string $id, string $reason): void
    {
        WebhookCall::query()->whereKey($id)->update([
            'status' => WebhookCallStatus::Failed->value,
            'exception' => $reason,
        ]);
    }
}
