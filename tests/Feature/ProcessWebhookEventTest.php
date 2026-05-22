<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Syriable\Payments\Data\WebhookEvent;
use Syriable\Payments\Events\PaymentRefunded;
use Syriable\Payments\Events\PaymentSucceeded;
use Syriable\Payments\Jobs\ProcessWebhookEvent;

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

    (new ProcessWebhookEvent(new WebhookEvent('stripe', 'payment.succeeded', 'pi_1')))->handle();

    Event::assertDispatched(PaymentSucceeded::class);
});

it('maps a refunded webhook type to the refunded event', function (): void {
    Event::fake([PaymentRefunded::class]);

    (new ProcessWebhookEvent(new WebhookEvent('stripe', 'payment.refunded', 'pi_1')))->handle();

    Event::assertDispatched(PaymentRefunded::class);
});
