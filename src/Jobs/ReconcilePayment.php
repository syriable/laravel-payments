<?php

declare(strict_types=1);

namespace Syriable\Payments\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Syriable\Payments\Data\WebhookEvent;
use Syriable\Payments\Enums\PaymentStatus;
use Syriable\Payments\Enums\WebhookEventType;
use Syriable\Payments\Events\PaymentFailed;
use Syriable\Payments\Events\PaymentRefunded;
use Syriable\Payments\Events\PaymentSucceeded;
use Syriable\Payments\GatewayManager;
use Syriable\Payments\Support\PaymentLog;

/**
 * Reconciles a payment whose webhook may have been missed: pulls the
 * authoritative state from the gateway and re-emits the canonical event so
 * the same listeners run as if the webhook had arrived.
 *
 * Non-terminal states (pending/processing/requires-action) emit nothing —
 * there is nothing to reconcile yet.
 */
final class ReconcilePayment implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $gateway,
        public readonly string $paymentId,
    ) {}

    public function handle(GatewayManager $manager): void
    {
        $result = $manager->gateway($this->gateway)->retrieve($this->paymentId);
        $type = $this->canonicalType($result->status);

        if ($type === null) {
            PaymentLog::info('payments.reconcile.pending', [
                'gateway' => $this->gateway,
                'payment_id' => $this->paymentId,
                'status' => $result->status->value,
            ]);

            return;
        }

        $event = new WebhookEvent(
            gateway: $this->gateway,
            type: $type,
            paymentId: $result->id,
            payload: $result->raw,
            reference: $result->reference,
            amount: $result->amount,
            currency: $result->currency,
        );

        PaymentLog::info('payments.reconcile.resolved', [
            'gateway' => $this->gateway,
            'payment_id' => $result->id,
            'reference' => $result->reference,
            'type' => $type->value,
        ]);

        match ($type) {
            WebhookEventType::Succeeded => PaymentSucceeded::dispatch($event),
            WebhookEventType::Failed => PaymentFailed::dispatch($event),
            WebhookEventType::Refunded => PaymentRefunded::dispatch($event),
            WebhookEventType::Unknown => null,
        };
    }

    private function canonicalType(PaymentStatus $status): ?WebhookEventType
    {
        return match ($status) {
            PaymentStatus::Paid => WebhookEventType::Succeeded,
            PaymentStatus::Failed, PaymentStatus::Canceled => WebhookEventType::Failed,
            PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded => WebhookEventType::Refunded,
            PaymentStatus::Pending, PaymentStatus::Processing, PaymentStatus::RequiresAction => null,
        };
    }
}
