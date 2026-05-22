<?php

declare(strict_types=1);

namespace Syriable\Payments\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Syriable\Payments\Facades\Gateway;
use Syriable\Payments\PaymentsServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            PaymentsServiceProvider::class,
        ];
    }

    /**
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return [
            'Gateway' => Gateway::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        $migration = include __DIR__.'/../database/migrations/create_payment_webhook_calls_table.php.stub';
        $migration->up();
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('payment-gateways.default', 'stripe');
        $app['config']->set('payment-gateways.gateways.stripe', [
            'secret' => 'sk_test_dummy',
            'webhook_secret' => 'whsec_test_dummy',
        ]);
        $app['config']->set('payment-gateways.gateways.paypal', [
            'mode' => 'sandbox',
            'client_id' => 'paypal_test_client_id',
            'client_secret' => 'paypal_test_client_secret',
            'webhook_id' => 'paypal_test_webhook_id',
            'default_currency' => 'USD',
        ]);
    }
}
