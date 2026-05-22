<?php

declare(strict_types=1);

use Syriable\Payments\Data\WebhookEvent;
use Syriable\Payments\Enums\WebhookCallStatus;
use Syriable\Payments\Enums\WebhookEventType;
use Syriable\Payments\Models\WebhookCall;
use Syriable\Payments\Store\DatabaseWebhookStore;

function storedEvent(?string $eventId = 'evt_1'): WebhookEvent
{
    return new WebhookEvent(
        gateway: 'stripe',
        type: WebhookEventType::Succeeded,
        paymentId: 'pi_1',
        payload: ['id' => $eventId, 'foo' => 'bar'],
        reference: 'order_99',
        amount: 2500,
        currency: 'USD',
        eventId: $eventId,
    );
}

it('persists a verified webhook and returns its id', function (): void {
    $id = (new DatabaseWebhookStore)->store(storedEvent());

    expect($id)->not->toBeNull();

    $call = WebhookCall::findOrFail($id);

    expect($call->gateway)->toBe('stripe')
        ->and($call->event_id)->toBe('evt_1')
        ->and($call->reference)->toBe('order_99')
        ->and($call->amount)->toBe(2500)
        ->and($call->currency)->toBe('USD')
        ->and($call->payload)->toBe(['id' => 'evt_1', 'foo' => 'bar'])
        ->and($call->status)->toBe(WebhookCallStatus::Pending);
});

it('is idempotent on the gateway event id', function (): void {
    $store = new DatabaseWebhookStore;

    $first = $store->store(storedEvent());
    $second = $store->store(storedEvent());

    expect($first)->toBe($second)
        ->and(WebhookCall::count())->toBe(1);
});

it('records events without an id as distinct rows', function (): void {
    $store = new DatabaseWebhookStore;

    $store->store(storedEvent(null));
    $store->store(storedEvent(null));

    expect(WebhookCall::count())->toBe(2);
});

it('marks a stored webhook processed', function (): void {
    $store = new DatabaseWebhookStore;
    $id = $store->store(storedEvent());

    $store->markProcessed($id);

    $call = WebhookCall::findOrFail($id);
    expect($call->status)->toBe(WebhookCallStatus::Processed)
        ->and($call->processed_at)->not->toBeNull();
});

it('marks a stored webhook failed with the reason', function (): void {
    $store = new DatabaseWebhookStore;
    $id = $store->store(storedEvent());

    $store->markFailed($id, 'listener exploded');

    $call = WebhookCall::findOrFail($id);
    expect($call->status)->toBe(WebhookCallStatus::Failed)
        ->and($call->exception)->toBe('listener exploded');
});
