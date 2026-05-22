<?php

declare(strict_types=1);

namespace Syriable\Payments\Enums;

/**
 * Canonical payment states surfaced by gateways.
 *
 * Kept small but complete enough to branch on the states that actually
 * change application behavior — notably RequiresAction (3DS/SCA) and
 * Canceled. Gateway-specific subtleties stay in PaymentResult::$raw.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case RequiresAction = 'requires_action';
    case Processing = 'processing';
    case Paid = 'paid';
    case Failed = 'failed';
    case Canceled = 'canceled';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
}
