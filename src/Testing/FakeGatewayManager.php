<?php

declare(strict_types=1);

namespace Syriable\Payments\Testing;

use Closure;
use PHPUnit\Framework\Assert as PHPUnit;
use Syriable\Payments\Contracts\Gateway;
use Syriable\Payments\Data\Checkout;
use Syriable\Payments\GatewayManager;

/**
 * Test double swapped into the container by Gateway::fake().
 *
 * Hands out FakeGateway instances (one per name, cached) and exposes
 * Mail/Queue-style assertion helpers.
 *
 * Extends GatewayManager so the facade's full surface continues to work
 * unchanged under test, but overrides driver() to return fakes.
 */
final class FakeGatewayManager extends GatewayManager
{
    /** @var array<string, FakeGateway> */
    private array $fakes = [];

    public function getDefaultDriver(): string
    {
        // Default driver name during tests is "fake" so callers without a
        // configured default still get a working facade.
        $configured = $this->config->get('payment-gateways.default');

        return is_string($configured) && $configured !== '' ? $configured : 'fake';
    }

    public function driver($driver = null): Gateway
    {
        $name = $driver ?? $this->getDefaultDriver();

        return $this->fakes[$name] ??= new FakeGateway($name);
    }

    public function gateway(?string $name = null): Gateway
    {
        return $this->driver($name);
    }

    /**
     * Inspect the underlying fake for a given driver name.
     */
    public function fakeFor(?string $name = null): FakeGateway
    {
        /** @var FakeGateway $fake */
        $fake = $this->driver($name);

        return $fake;
    }

    public function assertCheckedOut(?Closure $callback = null, ?string $name = null): void
    {
        $fake = $this->fakeFor($name);

        if ($callback === null) {
            PHPUnit::assertNotEmpty(
                $fake->checkouts,
                "Expected at least one checkout on gateway [{$fake->name()}], but none were recorded.",
            );

            return;
        }

        $matched = array_filter(
            $fake->checkouts,
            static fn (Checkout $checkout): bool => (bool) $callback($checkout),
        );

        PHPUnit::assertNotEmpty(
            $matched,
            "No checkout matching the given callback was recorded on gateway [{$fake->name()}].",
        );
    }

    public function assertRefunded(?Closure $callback = null, ?string $name = null): void
    {
        $fake = $this->fakeFor($name);

        if ($callback === null) {
            PHPUnit::assertNotEmpty(
                $fake->refunds,
                "Expected at least one refund on gateway [{$fake->name()}], but none were recorded.",
            );

            return;
        }

        $matched = array_filter(
            $fake->refunds,
            /** @param  array{paymentId: string, amount: int|null}  $refund */
            static fn (array $refund): bool => (bool) $callback($refund),
        );

        PHPUnit::assertNotEmpty(
            $matched,
            "No refund matching the given callback was recorded on gateway [{$fake->name()}].",
        );
    }

    public function assertNothingCharged(?string $name = null): void
    {
        $fake = $this->fakeFor($name);

        PHPUnit::assertEmpty(
            $fake->checkouts,
            "Expected no checkouts on gateway [{$fake->name()}], but ".count($fake->checkouts).' were recorded.',
        );
    }

    public function assertCheckoutCount(int $expected, ?string $name = null): void
    {
        $fake = $this->fakeFor($name);

        PHPUnit::assertCount(
            $expected,
            $fake->checkouts,
            "Expected exactly [{$expected}] checkouts on gateway [{$fake->name()}], got [".count($fake->checkouts).'].',
        );
    }
}
