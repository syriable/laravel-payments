<?php

declare(strict_types=1);

namespace Syriable\Payments;

use Illuminate\Support\Manager;
use Syriable\Payments\Contracts\Gateway;
use Syriable\Payments\Exceptions\GatewayNotConfigured;
use Syriable\Payments\Gateways\PayPal\PayPalGateway;
use Syriable\Payments\Gateways\Stripe\StripeGateway;

/**
 * Resolves named payment gateway drivers.
 *
 * This class is a thin extension of Illuminate\Support\Manager. Doing so
 * gives us — for free — driver instance caching, the driver() lookup
 * method, the extend() registration hook used by plugins, and __call()
 * passthrough to the default driver. See the framework's Manager class
 * for the inherited behavior.
 *
 * Adding a new built-in gateway is exactly one createXxxDriver() method.
 * Adding a third-party gateway requires no edits here at all — plugins
 * register themselves via the Gateway::extend() facade method.
 */
class GatewayManager extends Manager
{
    /**
     * Resolve a gateway driver by name (or the default driver when null).
     *
     * Alias of driver() for readability; both are kept because driver() is
     * what users coming from Cache::store() / Mail::mailer() / Storage::disk()
     * expect, and gateway() reads more naturally in payment code.
     *
     * When the requested driver has no built-in createXxxDriver() method and
     * no extend() registration, the parent Manager throws a plain
     * InvalidArgumentException. We translate that into GatewayNotConfigured so
     * callers (including the webhook controller) face a single, predictable
     * exception type from this package.
     */
    public function gateway(?string $name = null): Gateway
    {
        try {
            /** @var Gateway $driver */
            $driver = $this->driver($name);
        } catch (\InvalidArgumentException $e) {
            throw GatewayNotConfigured::unknownGateway($name ?? '(default)', $e);
        }

        return $driver;
    }

    public function getDefaultDriver(): string
    {
        /** @var string|null $default */
        $default = $this->config->get('payment-gateways.default');

        if ($default === null || $default === '') {
            throw GatewayNotConfigured::missingDefault();
        }

        return $default;
    }

    protected function createStripeDriver(): Gateway
    {
        return new StripeGateway($this->gatewayConfig('stripe'));
    }

    protected function createPaypalDriver(): Gateway
    {
        return new PayPalGateway($this->gatewayConfig('paypal'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function gatewayConfig(string $name): array
    {
        /** @var array<string, mixed> $config */
        $config = $this->config->get("payment-gateways.gateways.{$name}", []);

        return $config;
    }
}
