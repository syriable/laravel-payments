<?php

declare(strict_types=1);

namespace Syriable\Payments\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Syriable\Payments\Data\WebhookEvent;
use Syriable\Payments\Events\PaymentFailed;
use Syriable\Payments\Events\PaymentRefunded;
use Syriable\Payments\Events\PaymentSucceeded;

/**
 * Dispatches the canonical payment event for a verified webhook off the
 * request cycle, so a slow or failing listener can't make the gateway time
 * out and retry the delivery.
 */
final class ProcessWebhookEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly WebhookEvent $event) {}

    public function handle(): void
    {
        match (true) {
            str_ends_with($this->event->type, '.succeeded') => PaymentSucceeded::dispatch($this->event),
            str_ends_with($this->event->type, '.failed') => PaymentFailed::dispatch($this->event),
            str_ends_with($this->event->type, '.refunded') => PaymentRefunded::dispatch($this->event),
            default => null,
        };
    }
}
