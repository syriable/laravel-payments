<?php

declare(strict_types=1);

namespace Syriable\Payments\Enums;

/**
 * Canonical payment states surfaced by gateways.
 *
 * Kept deliberately small. We model only what consumers branch on in
 * application code; gateway-specific lifecycle subtleties (e.g.
 * "requires_action", "processing") are exposed via PaymentResult::$raw
 * for the cases that need them.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Refunded = 'refunded';
}
