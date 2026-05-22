<?php

declare(strict_types=1);

namespace Syriable\Payments\Contracts;

use Syriable\Payments\Data\WebhookEvent;

/**
 * Persists verified webhooks so the pipeline can acknowledge the gateway
 * immediately and process the event durably, off the request cycle.
 *
 * Implementations are swappable via the webhook.store config key. The
 * package ships a database-backed default and a null (no-op) store.
 */
interface WebhookStore
{
    /**
     * Persist a verified webhook before processing.
     *
     * Returns an opaque storage id used to update the record later, or null
     * when the store does not persist (e.g. NullWebhookStore).
     */
    public function store(WebhookEvent $event): ?string;

    public function markProcessed(string $id): void;

    public function markFailed(string $id, string $reason): void;
}
