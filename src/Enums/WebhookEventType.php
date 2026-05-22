<?php

declare(strict_types=1);

namespace Syriable\Payments\Enums;

/**
 * Canonical, cross-gateway webhook event vocabulary.
 *
 * Each gateway maps its own event names onto these cases; anything without a
 * canonical mapping becomes Unknown (the original name is still in the
 * event payload). A closed set keeps the dispatch match exhaustive.
 */
enum WebhookEventType: string
{
    case Succeeded = 'payment.succeeded';
    case Failed = 'payment.failed';
    case Refunded = 'payment.refunded';
    case Unknown = 'payment.unknown';
}
