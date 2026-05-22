<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Syriable\Payments\Contracts\Gateway as GatewayContract;
use Syriable\Payments\Data\Checkout;
use Syriable\Payments\Data\PaymentResult;
use Syriable\Payments\Data\WebhookEvent;
use Syriable\Payments\Enums\PaymentStatus;
use Syriable\Payments\Enums\WebhookEventType;
use Syriable\Payments\Events\PaymentRefunded;
use Syriable\Payments\Events\PaymentSucceeded;
use Syriable\Payments\Facades\Gateway;
use Syriable\Payments\GatewayManager;
use Syriable\Payments\Jobs\ReconcilePayment;

function fakeReconcileGateway(PaymentResult $result): void
{
    Gateway::extend('recon', fn () => new class($result) implements GatewayContract
    {
        public function __construct(private PaymentResult $result) {}

        public function name(): string
        {
            return 'recon';
        }

        public function checkout(Checkout $checkout): PaymentResult
        {
            return new PaymentResult('c1', PaymentStatus::Pending);
        }

        public function retrieve(string $paymentId): PaymentResult
        {
            return $this->result;
        }

        public function webhook(Request $request): WebhookEvent
        {
            return new WebhookEvent('recon', WebhookEventType::Unknown, 'x');
        }
    });
}

it('re-emits a succeeded event with the gateway figures', function (): void {
    Event::fake([PaymentSucceeded::class]);

    fakeReconcileGateway(new PaymentResult(
        id: 'pi_recon',
        status: PaymentStatus::Paid,
        reference: 'order_99',
        amount: 2500,
        currency: 'USD',
    ));

    (new ReconcilePayment('recon', 'pi_recon'))->handle(app(GatewayManager::class));

    Event::assertDispatched(
        PaymentSucceeded::class,
        static fn (PaymentSucceeded $e): bool => $e->event->reference === 'order_99'
            && $e->event->amount === 2500
            && $e->event->paymentId === 'pi_recon',
    );
});

it('maps a refunded retrieve to the refunded event', function (): void {
    Event::fake([PaymentRefunded::class]);

    fakeReconcileGateway(new PaymentResult('pi_r', PaymentStatus::Refunded));

    (new ReconcilePayment('recon', 'pi_r'))->handle(app(GatewayManager::class));

    Event::assertDispatched(PaymentRefunded::class);
});

it('emits nothing while the payment is still pending', function (): void {
    Event::fake([PaymentSucceeded::class, PaymentRefunded::class]);

    fakeReconcileGateway(new PaymentResult('pi_p', PaymentStatus::Processing));

    (new ReconcilePayment('recon', 'pi_p'))->handle(app(GatewayManager::class));

    Event::assertNotDispatched(PaymentSucceeded::class);
    Event::assertNotDispatched(PaymentRefunded::class);
});

it('queues the reconcile job from the command', function (): void {
    Queue::fake();

    $this->artisan('payment:reconcile', ['gateway' => 'stripe', 'payment' => 'pi_1'])
        ->assertSuccessful();

    Queue::assertPushed(
        ReconcilePayment::class,
        static fn (ReconcilePayment $job): bool => $job->gateway === 'stripe' && $job->paymentId === 'pi_1',
    );
});

it('reconciles inline with the --sync flag', function (): void {
    Event::fake([PaymentSucceeded::class]);

    fakeReconcileGateway(new PaymentResult('pi_sync', PaymentStatus::Paid, reference: 'order_1'));

    $this->artisan('payment:reconcile', ['gateway' => 'recon', 'payment' => 'pi_sync', '--sync' => true])
        ->assertSuccessful();

    Event::assertDispatched(PaymentSucceeded::class);
});
