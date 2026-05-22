<?php

declare(strict_types=1);

namespace Syriable\Payments\Console;

use Illuminate\Console\Command;
use Syriable\Payments\GatewayManager;
use Syriable\Payments\Jobs\ReconcilePayment;

/**
 * Reconciles a single payment from the CLI. The package doesn't own your
 * orders table, so pass the gateway and the payment/order id you stored;
 * iterate your own non-final orders to reconcile in bulk.
 */
final class ReconcilePaymentCommand extends Command
{
    protected $signature = 'payment:reconcile
        {gateway : The gateway name (e.g. stripe, paypal)}
        {payment : The gateway payment/order id stored on your order}
        {--sync : Reconcile inline instead of queueing the job}';

    protected $description = 'Reconcile a payment with the gateway and re-emit its canonical event.';

    public function handle(GatewayManager $manager): int
    {
        $gateway = (string) $this->argument('gateway');
        $payment = (string) $this->argument('payment');

        if ($this->option('sync')) {
            (new ReconcilePayment($gateway, $payment))->handle($manager);
            $this->info("Reconciled {$gateway} payment {$payment}.");

            return self::SUCCESS;
        }

        ReconcilePayment::dispatch($gateway, $payment);
        $this->info("Queued reconciliation for {$gateway} payment {$payment}.");

        return self::SUCCESS;
    }
}
