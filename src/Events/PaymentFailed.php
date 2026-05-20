<?php

declare(strict_types=1);

namespace Syriable\Payments\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Syriable\Payments\Data\WebhookEvent;

final class PaymentFailed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly WebhookEvent $event) {}
}
