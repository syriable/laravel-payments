<?php

declare(strict_types=1);

namespace Syriable\Payments\Gateways\Concerns;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Adds a timeout and transient-error retries (with backoff) to a gateway's
 * HTTP client. Safe to retry writes because checkouts carry idempotency keys.
 */
trait ResilientHttp
{
    private function applyResilience(PendingRequest $request): PendingRequest
    {
        return $request
            ->timeout($this->httpConfig('timeout', 30))
            // throw: false so terminal errors fall through to our own
            // PaymentFailed handling instead of escaping the request call.
            ->retry($this->httpConfig('retry.times', 3), $this->retryBackoff(), $this->retryWhen(), throw: false);
    }

    private function retryBackoff(): Closure
    {
        $base = $this->httpConfig('retry.sleep_ms', 200);

        return fn (int $attempt): int => $base * (2 ** ($attempt - 1));
    }

    private function retryWhen(): Closure
    {
        // Retry only on network failures and transient server/rate-limit
        // responses — never on a real decline or bad request.
        return function (Throwable $e): bool {
            if ($e instanceof ConnectionException) {
                return true;
            }

            $status = $e instanceof RequestException ? $e->response->status() : 0;

            return in_array($status, [429, 500, 502, 503, 504], true);
        };
    }

    private function httpConfig(string $key, int $default): int
    {
        $value = config("payment-gateways.http.{$key}");

        return is_numeric($value) ? (int) $value : $default;
    }
}
