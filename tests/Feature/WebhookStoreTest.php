<?php

declare(strict_types=1);

use Syriable\Payments\Contracts\WebhookStore;
use Syriable\Payments\Data\WebhookEvent;
use Syriable\Payments\Enums\WebhookEventType;
use Syriable\Payments\Store\NullWebhookStore;

it('resolves the configured webhook store from the container', function (): void {
    expect(app(WebhookStore::class))->toBeInstanceOf(NullWebhookStore::class);
});

it('null store persists nothing and treats updates as no-ops', function (): void {
    $store = new NullWebhookStore;
    $event = new WebhookEvent('stripe', WebhookEventType::Succeeded, 'pi_1');

    $store->markProcessed('anything');
    $store->markFailed('anything', 'boom');

    expect($store->store($event))->toBeNull();
});
