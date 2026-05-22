<?php

declare(strict_types=1);

namespace Syriable\Payments\Support;

use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper around the logger so payment activity lands on a configurable
 * channel. Only ever pass ids, references, amounts, and types here — never
 * secrets or full gateway payloads.
 */
final class PaymentLog
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function write(string $level, string $message, array $context): void
    {
        $channel = config('payment-gateways.logging.channel');

        if (is_string($channel) && $channel !== '') {
            Log::channel($channel)->{$level}($message, $context);

            return;
        }

        Log::{$level}($message, $context);
    }
}
