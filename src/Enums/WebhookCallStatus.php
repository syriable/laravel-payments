<?php

declare(strict_types=1);

namespace Syriable\Payments\Enums;

/**
 * Lifecycle of a persisted webhook: pending until the queued job runs,
 * then processed or failed.
 */
enum WebhookCallStatus: string
{
    case Pending = 'pending';
    case Processed = 'processed';
    case Failed = 'failed';
}
