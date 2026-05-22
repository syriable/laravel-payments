<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Syriable\Payments\Data\WebhookEvent;
use Syriable\Payments\Enums\WebhookCallStatus;
use Syriable\Payments\Enums\WebhookEventType;
use Syriable\Payments\Events\PaymentRefunded;
use Syriable\Payments\Events\PaymentSucceeded;
use Syriable\Payments\Jobs\ProcessWebhookEvent;
use Syriable\Payments\Models\WebhookCall;
use Syriable\Payments\Store\DatabaseWebhookStore;
use Syriable\Payments\Store\NullWebhookStore;

it('queues processing instead of dispatching inline', function (): void {
    Queue::fake();

    $secret = 'whsec_test_dummy';
    $body = json_encode([
        'id' => 'evt_q_1',
        'type' => 'checkout.session.completed',
        'data' => ['object' => ['id' => 'cs_q_1']],
    ]);
    $timestamp = time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

    $this->call(
        'POST',
        '/payment-gateways/webhook/stripe',
        server: ['HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}", 'CONTENT_TYPE' => 'application/json'],
        content: $body,
    )->assertOk();

    Queue::assertPushed(ProcessWebhookEvent::class, 1);
});

it('dispatches the canonical event when the job runs', function (): void {
    Event::fake([PaymentSucceeded::class]);

    (new ProcessWebhookEvent(new WebhookEvent('stripe', WebhookEventType::Succeeded, 'pi_1')))
        ->handle(new NullWebhookStore);

    Event::assertDispatched(PaymentSucceeded::class);
});

it('maps a refunded webhook type to the refunded event', function (): void {
    Event::fake([PaymentRefunded::class]);

    (new ProcessWebhookEvent(new WebhookEvent('stripe', WebhookEventType::Refunded, 'pi_1')))
        ->handle(new NullWebhookStore);

    Event::assertDispatched(PaymentRefunded::class);
});

it('marks the stored webhook processed after a successful run', function (): void {
    Event::fake([PaymentSucceeded::class]);

    $store = new DatabaseWebhookStore;
    $event = new WebhookEvent('stripe', WebhookEventType::Succeeded, 'pi_1', eventId: 'evt_p_1');
    $id = $store->store($event);

    (new ProcessWebhookEvent($event, $id))->handle($store);

    expect(WebhookCall::findOrFail($id)->status)->toBe(WebhookCallStatus::Processed);
});

it('marks the stored webhook failed and rethrows when a listener throws', function (): void {
    Event::listen(PaymentSucceeded::class, function (): void {
        throw new RuntimeException('listener boom');
    });

    $store = new DatabaseWebhookStore;
    $event = new WebhookEvent('stripe', WebhookEventType::Succeeded, 'pi_1', eventId: 'evt_f_1');
    $id = $store->store($event);

    expect(fn () => (new ProcessWebhookEvent($event, $id))->handle($store))
        ->toThrow(RuntimeException::class, 'listener boom');

    $call = WebhookCall::findOrFail($id);
    expect($call->status)->toBe(WebhookCallStatus::Failed)
        ->and($call->exception)->toBe('listener boom');
});
