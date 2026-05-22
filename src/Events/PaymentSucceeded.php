<?php

declare(strict_types=1);

namespace Syriable\Payments\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Syriable\Payments\Data\WebhookEvent;

/**
 * Fired when a verified webhook (or a reconciliation) resolves to a
 * "payment.succeeded" event.
 *
 * Consumers listen to this in their application and mark the relevant order
 * as paid — fulfilling the order is the consuming app's concern.
 */
final class PaymentSucceeded
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly WebhookEvent $event) {}
}
