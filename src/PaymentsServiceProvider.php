<?php

declare(strict_types=1);

namespace Syriable\Payments;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * The package's only service provider.
 *
 * Built on spatie/laravel-package-tools so config/route registration is
 * one fluent declaration. See https://github.com/spatie/laravel-package-tools
 *
 * Naming: the package is "laravel-payments", which Spatie's tools shorten
 * to "payments" when generating publish tags. The config file ships as
 * "payment-gateways.php" because that's what it manages; the publish tag
 * resolves to "payments-config".
 */
class PaymentsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-payments')
            ->hasConfigFile('payment-gateways')
            ->hasRoute('webhooks');
    }

    public function packageRegistered(): void
    {
        $this->app->singleton('payment-gateways', static fn ($app): GatewayManager => new GatewayManager($app));

        $this->app->alias('payment-gateways', GatewayManager::class);
    }
}
