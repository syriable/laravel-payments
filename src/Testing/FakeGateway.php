<?php

declare(strict_types=1);

namespace Syriable\Payments\Testing;

use Illuminate\Http\Request;
use Syriable\Payments\Contracts\Capturable;
use Syriable\Payments\Contracts\Gateway;
use Syriable\Payments\Contracts\Refundable;
use Syriable\Payments\Data\Checkout;
use Syriable\Payments\Data\PaymentResult;
use Syriable\Payments\Data\WebhookEvent;
use Syriable\Payments\Enums\PaymentStatus;
use Syriable\Payments\Enums\WebhookEventType;

/**
 * In-memory fake gateway used by Gateway::fake().
 *
 * Records every call made to it, returns deterministic PaymentResult
 * objects, and implements every capability interface so consumer code
 * branching on `instanceof Refundable` still works in tests.
 */
final class FakeGateway implements Capturable, Gateway, Refundable
{
    /** @var list<Checkout> */
    public array $checkouts = [];

    /** @var list<array{paymentId: string, amount: int|null}> */
    public array $refunds = [];

    /** @var list<array{paymentId: string, amount: int|null}> */
    public array $captures = [];

    /** @var list<string> */
    public array $retrievals = [];

    public function __construct(private readonly string $name = 'fake') {}

    public function name(): string
    {
        return $this->name;
    }

    public function checkout(Checkout $checkout): PaymentResult
    {
        $this->checkouts[] = $checkout;

        return new PaymentResult(
            id: 'fake_checkout_'.count($this->checkouts),
            status: PaymentStatus::Pending,
            redirectUrl: $checkout->successUrl,
            raw: ['fake' => true],
        );
    }

    public function retrieve(string $paymentId): PaymentResult
    {
        $this->retrievals[] = $paymentId;

        return new PaymentResult(
            id: $paymentId,
            status: PaymentStatus::Paid,
            raw: ['fake' => true, 'payment_id' => $paymentId],
        );
    }

    public function refund(string $paymentId, ?int $amount = null): PaymentResult
    {
        $this->refunds[] = ['paymentId' => $paymentId, 'amount' => $amount];

        return new PaymentResult(
            id: 'fake_refund_'.count($this->refunds),
            status: PaymentStatus::Refunded,
            raw: ['fake' => true, 'payment_id' => $paymentId],
        );
    }

    public function capture(string $paymentId, ?int $amount = null): PaymentResult
    {
        $this->captures[] = ['paymentId' => $paymentId, 'amount' => $amount];

        return new PaymentResult(
            id: 'fake_capture_'.count($this->captures),
            status: PaymentStatus::Paid,
            raw: ['fake' => true, 'payment_id' => $paymentId],
        );
    }

    public function webhook(Request $request): WebhookEvent
    {
        /** @var array<array-key, mixed> $payload */
        $payload = $request->json()->all();

        return new WebhookEvent(
            gateway: $this->name,
            type: WebhookEventType::Succeeded,
            paymentId: 'fake_webhook_payment',
            payload: $payload,
        );
    }
}
