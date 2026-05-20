<?php

declare(strict_types=1);

namespace Syriable\Payments\Gateways\Stripe;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Syriable\Payments\Contracts\Gateway;
use Syriable\Payments\Contracts\Refundable;
use Syriable\Payments\Data\Checkout;
use Syriable\Payments\Data\PaymentResult;
use Syriable\Payments\Data\WebhookEvent;
use Syriable\Payments\Enums\PaymentStatus;
use Syriable\Payments\Exceptions\GatewayNotConfigured;
use Syriable\Payments\Exceptions\InvalidWebhookSignature;
use Syriable\Payments\Exceptions\PaymentFailed;

/**
 * Stripe gateway driver.
 *
 * Uses Laravel's HTTP client rather than stripe/stripe-php so the package
 * carries no hard SDK dependency. Stripe's REST API is stable and HMAC
 * webhook verification is well documented at:
 * https://docs.stripe.com/webhooks#verify-manually
 *
 * Consumers who prefer the SDK can wire it themselves via Gateway::extend('stripe', ...).
 */
final class StripeGateway implements Gateway, Refundable
{
    private const API_BASE = 'https://api.stripe.com/v1';

    private const NAME = 'stripe';

    /**
     * Webhook signature time tolerance, in seconds. Matches Stripe's
     * default of 5 minutes.
     */
    private const SIGNATURE_TOLERANCE = 300;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly array $config,
        private readonly ?HttpFactory $http = null,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function checkout(Checkout $checkout): PaymentResult
    {
        $payload = [
            'mode' => 'payment',
            'success_url' => $checkout->successUrl,
            'cancel_url' => $checkout->cancelUrl,
            'client_reference_id' => $checkout->reference,
            'line_items[0][quantity]' => 1,
            'line_items[0][price_data][currency]' => strtolower($checkout->currency),
            'line_items[0][price_data][unit_amount]' => $checkout->amount,
            'line_items[0][price_data][product_data][name]' => $checkout->reference,
        ];

        if ($checkout->customerEmail !== null) {
            $payload['customer_email'] = $checkout->customerEmail;
        }

        foreach ($checkout->metadata as $key => $value) {
            $payload["metadata[{$key}]"] = $value;
        }

        $response = $this->client()
            ->asForm()
            ->post(self::API_BASE.'/checkout/sessions', $payload);

        try {
            $response->throw();
        } catch (RequestException $exception) {
            throw PaymentFailed::fromGateway(
                self::NAME,
                'Failed to create checkout session: '.$exception->getMessage(),
                (array) $response->json(),
            );
        }

        /** @var array<string, mixed> $session */
        $session = (array) $response->json();

        $url = $session['url'] ?? null;

        return new PaymentResult(
            id: (string) ($session['id'] ?? ''),
            status: PaymentStatus::Pending,
            redirectUrl: is_string($url) ? $url : null,
            raw: $session,
        );
    }

    public function refund(string $paymentId, ?int $amount = null): PaymentResult
    {
        $payload = ['payment_intent' => $paymentId];

        if ($amount !== null) {
            $payload['amount'] = $amount;
        }

        $response = $this->client()
            ->asForm()
            ->post(self::API_BASE.'/refunds', $payload);

        try {
            $response->throw();
        } catch (RequestException $exception) {
            throw PaymentFailed::fromGateway(
                self::NAME,
                'Refund failed: '.$exception->getMessage(),
                (array) $response->json(),
            );
        }

        /** @var array<string, mixed> $refund */
        $refund = (array) $response->json();

        return new PaymentResult(
            id: (string) ($refund['id'] ?? ''),
            status: PaymentStatus::Refunded,
            raw: $refund,
        );
    }

    public function webhook(Request $request): WebhookEvent
    {
        $secret = $this->requiredConfig('webhook_secret');

        $signatureHeader = $request->header('Stripe-Signature');
        $payload = $request->getContent();

        if (! is_string($signatureHeader) || $signatureHeader === '') {
            throw InvalidWebhookSignature::forGateway(self::NAME);
        }

        $this->verifySignature($payload, $signatureHeader, $secret);

        /** @var array{type?: string, data?: array{object?: array<string, mixed>}} $event */
        $event = (array) json_decode($payload, true);

        $stripeType = (string) ($event['type'] ?? '');
        $object = (array) ($event['data']['object'] ?? []);
        $paymentId = (string) ($object['id'] ?? '');

        return new WebhookEvent(
            gateway: self::NAME,
            type: $this->normalizeEventType($stripeType),
            paymentId: $paymentId,
            payload: $event,
        );
    }

    /**
     * Verifies a Stripe webhook signature per the algorithm documented at
     * https://docs.stripe.com/webhooks#verify-manually
     */
    private function verifySignature(string $payload, string $header, string $secret): void
    {
        $parts = [];
        foreach (explode(',', $header) as $segment) {
            $kv = explode('=', $segment, 2);
            if (count($kv) === 2) {
                $parts[trim($kv[0])][] = trim($kv[1]);
            }
        }

        if (! isset($parts['t'][0]) || ! isset($parts['v1'])) {
            throw InvalidWebhookSignature::forGateway(self::NAME);
        }

        $timestamp = (int) $parts['t'][0];

        if (abs(time() - $timestamp) > self::SIGNATURE_TOLERANCE) {
            throw InvalidWebhookSignature::forGateway(self::NAME);
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        foreach ($parts['v1'] as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return;
            }
        }

        throw InvalidWebhookSignature::forGateway(self::NAME);
    }

    private function normalizeEventType(string $stripeType): string
    {
        return match ($stripeType) {
            'checkout.session.completed', 'payment_intent.succeeded' => 'payment.succeeded',
            'payment_intent.payment_failed', 'checkout.session.expired' => 'payment.failed',
            'charge.refunded' => 'payment.refunded',
            default => 'payment.'.$stripeType,
        };
    }

    private function client(): PendingRequest
    {
        $secret = $this->requiredConfig('secret');

        $factory = $this->http ?? Http::getFacadeRoot();
        assert($factory instanceof HttpFactory);

        return $factory
            ->withToken($secret)
            ->acceptJson()
            ->timeout(30);
    }

    private function requiredConfig(string $key): string
    {
        $value = $this->config[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw GatewayNotConfigured::missingCredentials(self::NAME, $key);
        }

        return $value;
    }
}
