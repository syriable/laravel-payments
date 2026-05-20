<?php

declare(strict_types=1);

namespace Syriable\Payments\Facades;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Syriable\Payments\Contracts\Gateway as GatewayContract;
use Syriable\Payments\Data\Checkout;
use Syriable\Payments\Data\PaymentResult;
use Syriable\Payments\Data\WebhookEvent;
use Syriable\Payments\GatewayManager;
use Syriable\Payments\Testing\FakeGatewayManager;

/**
 * @method static GatewayContract gateway(?string $name = null)
 * @method static GatewayContract driver(?string $driver = null)
 * @method static GatewayManager extend(string $driver, Closure $callback)
 * @method static PaymentResult checkout(Checkout $checkout)
 * @method static WebhookEvent webhook(Request $request)
 *
 * @see GatewayManager
 */
class Gateway extends Facade
{
    public static function fake(): FakeGatewayManager
    {
        $app = static::getFacadeApplication() ?? Container::getInstance();

        $fake = new FakeGatewayManager($app);

        static::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return 'payment-gateways';
    }
}
