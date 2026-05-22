<?php

declare(strict_types=1);

namespace Syriable\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Syriable\Payments\Enums\WebhookCallStatus;

/**
 * A persisted webhook delivery. Holds the verified payload and the
 * extracted, queryable money fields so processing is durable and auditable.
 *
 * @property int $id
 * @property string $gateway
 * @property string|null $event_id
 * @property string $type
 * @property string|null $payment_id
 * @property string|null $reference
 * @property int|null $amount
 * @property string|null $currency
 * @property array<array-key, mixed> $payload
 * @property WebhookCallStatus $status
 * @property string|null $exception
 * @property Carbon|null $processed_at
 */
final class WebhookCall extends Model
{
    protected $table = 'payment_webhook_calls';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'amount' => 'integer',
            'status' => WebhookCallStatus::class,
            'processed_at' => 'datetime',
        ];
    }
}
