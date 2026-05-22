<?php

declare(strict_types=1);

namespace Syriable\Payments\Store;

use Syriable\Payments\Contracts\WebhookStore;
use Syriable\Payments\Data\WebhookEvent;

/**
 * A no-op store for apps that don't want webhooks persisted. Processing
 * still happens; nothing is recorded.
 */
final class NullWebhookStore implements WebhookStore
{
    public function store(WebhookEvent $event): ?string
    {
        return null;
    }

    public function markProcessed(string $id): void {}

    public function markFailed(string $id, string $reason): void {}
}
