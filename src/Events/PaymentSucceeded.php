<?php

declare(strict_types=1);

namespace Syriable\Payments\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Syriable\Payments\Data\WebhookEvent;

/**
 * Fired by the package's webhook controller when a verified webhook
 * resolves to a "payment.succeeded" event.
 *
 * Consumers listen to this in their EventServiceProvider and mark the
 * relevant order as paid. The package itself never touches the database.
 */
final class PaymentSucceeded
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly WebhookEvent $event) {}
}
