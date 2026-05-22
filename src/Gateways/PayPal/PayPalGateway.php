<?php

declare(strict_types=1);

namespace Syriable\Payments\Gateways\PayPal;

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
use Syriable\Payments\Enums\WebhookEventType;
use Syriable\Payments\Exceptions\GatewayNotConfigured;
use Syriable\Payments\Exceptions\InvalidWebhookSignature;
use Syriable\Payments\Exceptions\PaymentFailed;

/**
 * PayPal gateway driver (Orders v2 API).
 *
 * Authenticates via OAuth2 client credentials, creates orders against
 * /v2/checkout/orders, and verifies webhooks via PayPal's
 * /v1/notifications/verify-webhook-signature endpoint as documented at:
 * https://developer.paypal.com/api/rest/webhooks/rest/
 *
 * Mode is selected via config: 'sandbox' (default) or 'live'.
 */
final class PayPalGateway implements Gateway, Refundable
{
    private const NAME = 'paypal';

    private const LIVE_BASE = 'https://api-m.paypal.com';

    private const SANDBOX_BASE = 'https://api-m.sandbox.paypal.com';

    /**
     * Cache for the OAuth access token within a single gateway instance.
     */
    private ?string $accessToken = null;

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
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $checkout->reference,
                'amount' => [
                    'currency_code' => $checkout->currency,
                    'value' => $this->toMajorUnits($checkout->amount, $checkout->currency),
                ],
                'custom_id' => $checkout->reference,
            ]],
            'application_context' => [
                'return_url' => $checkout->successUrl,
                'cancel_url' => $checkout->cancelUrl,
                'user_action' => 'PAY_NOW',
            ],
        ];

        if ($checkout->customerEmail !== null) {
            $payload['payer'] = ['email_address' => $checkout->customerEmail];
        }

        $response = $this->authedClient()
            // PayPal-Request-Id makes a retried create reuse the same order
            // instead of creating a duplicate.
            ->withHeaders(['PayPal-Request-Id' => 'checkout_'.$checkout->reference])
            ->post($this->baseUrl().'/v2/checkout/orders', $payload);

        try {
            $response->throw();
        } catch (RequestException $exception) {
            throw PaymentFailed::fromGateway(
                self::NAME,
                'Failed to create order: '.$exception->getMessage(),
                (array) $response->json(),
            );
        }

        /** @var array<string, mixed> $order */
        $order = (array) $response->json();

        /** @var list<array<string, mixed>> $links */
        $links = is_array($order['links'] ?? null) ? $order['links'] : [];

        return new PaymentResult(
            id: (string) ($order['id'] ?? ''),
            status: PaymentStatus::Pending,
            redirectUrl: $this->approveLink($links),
            raw: $order,
        );
    }

    public function retrieve(string $paymentId): PaymentResult
    {
        // $paymentId is the order id returned at checkout.
        $response = $this->authedClient()
            ->get($this->baseUrl().'/v2/checkout/orders/'.$paymentId);

        try {
            $response->throw();
        } catch (RequestException $exception) {
            throw PaymentFailed::fromGateway(
                self::NAME,
                'Failed to retrieve order: '.$exception->getMessage(),
                (array) $response->json(),
            );
        }

        /** @var array<string, mixed> $order */
        $order = (array) $response->json();

        return new PaymentResult(
            id: (string) ($order['id'] ?? $paymentId),
            status: $this->mapOrderStatus((string) ($order['status'] ?? '')),
            raw: $order,
        );
    }

    public function refund(string $paymentId, ?int $amount = null): PaymentResult
    {
        // $paymentId here is the capture ID (per PayPal's refund endpoint).
        $payload = [];

        if ($amount !== null) {
            // The currency must be the original capture's currency; we can't
            // discover that without an extra round-trip, so consumers passing
            // a partial refund amount should pass it in minor units of the
            // original currency, and pass the currency via metadata if their
            // accounting flow needs it. For full refunds, omit $amount.
            $currency = (string) ($this->config['default_currency'] ?? 'USD');
            $payload['amount'] = [
                'currency_code' => $currency,
                'value' => $this->toMajorUnits($amount, $currency),
            ];
        }

        $response = $this->authedClient()
            ->post($this->baseUrl()."/v2/payments/captures/{$paymentId}/refund", $payload);

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
        $webhookId = $this->requiredConfig('webhook_id');

        $verifyPayload = [
            'auth_algo' => $request->header('PAYPAL-AUTH-ALGO'),
            'cert_url' => $request->header('PAYPAL-CERT-URL'),
            'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID'),
            'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG'),
            'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
            'webhook_id' => $webhookId,
            'webhook_event' => $request->json()->all(),
        ];

        foreach (['auth_algo', 'cert_url', 'transmission_id', 'transmission_sig', 'transmission_time'] as $required) {
            if (! is_string($verifyPayload[$required]) || $verifyPayload[$required] === '') {
                throw InvalidWebhookSignature::forGateway(self::NAME);
            }
        }

        $response = $this->authedClient()
            ->post($this->baseUrl().'/v1/notifications/verify-webhook-signature', $verifyPayload);

        if (! $response->successful() || $response->json('verification_status') !== 'SUCCESS') {
            throw InvalidWebhookSignature::forGateway(self::NAME);
        }

        /** @var array{id?: string, event_type?: string, resource?: array<string, mixed>} $event */
        $event = $request->json()->all();

        $paypalType = (string) ($event['event_type'] ?? '');
        $resource = (array) ($event['resource'] ?? []);
        $paymentId = (string) ($resource['id'] ?? '');

        return new WebhookEvent(
            gateway: self::NAME,
            type: $this->normalizeEventType($paypalType),
            paymentId: $paymentId,
            payload: $event,
            reference: $this->extractReference($resource),
            amount: $this->extractAmount($resource),
            currency: $this->extractCurrency($resource),
            eventId: is_string($event['id'] ?? null) ? $event['id'] : null,
        );
    }

    /**
     * Pull our checkout reference back out of a webhook resource. Capture
     * resources carry custom_id; order resources carry it on a purchase unit.
     *
     * @param  array<string, mixed>  $resource
     */
    private function extractReference(array $resource): ?string
    {
        $custom = $resource['custom_id'] ?? null;
        if (is_string($custom) && $custom !== '') {
            return $custom;
        }

        $units = $resource['purchase_units'] ?? null;
        if (is_array($units) && is_array($units[0] ?? null)) {
            foreach (['custom_id', 'reference_id'] as $key) {
                $value = $units[0][$key] ?? null;
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * PayPal reports amounts as major-unit decimal strings; normalize back to
     * the integer minor units this package uses everywhere else.
     *
     * @param  array<string, mixed>  $resource
     */
    private function extractAmount(array $resource): ?int
    {
        $amount = $resource['amount'] ?? null;
        if (! is_array($amount)) {
            return null;
        }

        $value = $amount['value'] ?? null;
        $currency = $amount['currency_code'] ?? null;
        if (! is_string($value) || ! is_numeric($value) || ! is_string($currency)) {
            return null;
        }

        return $this->toMinorUnits($value, $currency);
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function extractCurrency(array $resource): ?string
    {
        $amount = $resource['amount'] ?? null;
        $currency = is_array($amount) ? ($amount['currency_code'] ?? null) : null;

        return is_string($currency) && $currency !== '' ? strtoupper($currency) : null;
    }

    private function mapOrderStatus(string $status): PaymentStatus
    {
        return match ($status) {
            'COMPLETED' => PaymentStatus::Paid,
            'VOIDED' => PaymentStatus::Canceled,
            'PAYER_ACTION_REQUIRED' => PaymentStatus::RequiresAction,
            default => PaymentStatus::Pending,
        };
    }

    private function normalizeEventType(string $paypalType): WebhookEventType
    {
        return match ($paypalType) {
            'CHECKOUT.ORDER.APPROVED',
            'PAYMENT.CAPTURE.COMPLETED' => WebhookEventType::Succeeded,
            'PAYMENT.CAPTURE.DENIED',
            'PAYMENT.CAPTURE.DECLINED' => WebhookEventType::Failed,
            'PAYMENT.CAPTURE.REFUNDED' => WebhookEventType::Refunded,
            default => WebhookEventType::Unknown,
        };
    }

    /**
     * Extract the "approve" link from an Orders API response.
     *
     * The links come from PayPal's JSON response, so individual entries are
     * not guaranteed to contain both keys; the lookups stay defensive.
     *
     * @param  list<array<string, mixed>>  $links
     */
    private function approveLink(array $links): ?string
    {
        foreach ($links as $link) {
            if (($link['rel'] ?? null) === 'approve') {
                $href = $link['href'] ?? null;

                return is_string($href) ? $href : null;
            }
        }

        return null;
    }

    private function authedClient(): PendingRequest
    {
        $factory = $this->http ?? Http::getFacadeRoot();
        assert($factory instanceof HttpFactory);

        return $factory
            ->withToken($this->accessToken())
            ->acceptJson()
            ->asJson()
            ->timeout(30);
    }

    private function accessToken(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        $clientId = $this->requiredConfig('client_id');
        $clientSecret = $this->requiredConfig('client_secret');

        $factory = $this->http ?? Http::getFacadeRoot();
        assert($factory instanceof HttpFactory);

        $response = $factory
            ->withBasicAuth($clientId, $clientSecret)
            ->asForm()
            ->acceptJson()
            ->timeout(30)
            ->post($this->baseUrl().'/v1/oauth2/token', ['grant_type' => 'client_credentials']);

        try {
            $response->throw();
        } catch (RequestException $exception) {
            throw PaymentFailed::fromGateway(
                self::NAME,
                'OAuth token request failed: '.$exception->getMessage(),
                (array) $response->json(),
            );
        }

        /** @var array<string, mixed> $body */
        $body = (array) $response->json();

        if (! isset($body['access_token']) || ! is_string($body['access_token'])) {
            throw PaymentFailed::fromGateway(self::NAME, 'OAuth response missing access_token.', $body);
        }

        return $this->accessToken = $body['access_token'];
    }

    private function baseUrl(): string
    {
        $mode = (string) ($this->config['mode'] ?? 'sandbox');

        return $mode === 'live' ? self::LIVE_BASE : self::SANDBOX_BASE;
    }

    private function requiredConfig(string $key): string
    {
        $value = $this->config[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw GatewayNotConfigured::missingCredentials(self::NAME, $key);
        }

        return $value;
    }

    /**
     * Convert minor units (integer) to the major-unit decimal string
     * PayPal expects (e.g. 2500 -> "25.00"). We use integer math and
     * sprintf so no floats touch the amount.
     */
    private function toMajorUnits(int $amount, string $currency): string
    {
        $exponent = $this->currencyExponent($currency);

        if ($exponent === 0) {
            return (string) $amount;
        }

        $divisor = 10 ** $exponent;
        $whole = intdiv($amount, $divisor);
        $fraction = abs($amount % $divisor);

        return sprintf("%d.%0{$exponent}d", $whole, $fraction);
    }

    /**
     * Convert a major-unit decimal string back to integer minor units
     * (e.g. "25.99" -> 2599). Integer math only; no floats touch the amount.
     */
    private function toMinorUnits(string $value, string $currency): int
    {
        $exponent = $this->currencyExponent($currency);

        $parts = explode('.', $value, 2);
        $whole = (int) $parts[0];
        $fraction = $exponent === 0
            ? 0
            : (int) str_pad(substr($parts[1] ?? '', 0, $exponent), $exponent, '0');

        return $whole * (10 ** $exponent) + $fraction;
    }

    /**
     * Decimal-place count for an ISO 4217 currency. Covers the common
     * exceptions to the default 2-decimal rule; gateways that need
     * unusual currencies can extend or override.
     */
    private function currencyExponent(string $currency): int
    {
        return match (strtoupper($currency)) {
            'JPY', 'KRW', 'VND', 'XAF', 'XOF', 'XPF', 'CLP', 'PYG', 'RWF', 'UGX', 'BIF', 'DJF', 'GNF', 'KMF' => 0,
            'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND' => 3,
            default => 2,
        };
    }
}
