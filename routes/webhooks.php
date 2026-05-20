<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Syriable\Payments\Http\WebhookController;

if (config('payment-gateways.webhook.enabled', true) === false) {
    return;
}

Route::middleware(config('payment-gateways.webhook.middleware', []))
    ->prefix(config('payment-gateways.webhook.prefix', 'payment-gateways'))
    ->group(function (): void {
        Route::post('webhook/{gateway}', WebhookController::class)
            ->name('payment-gateways.webhook');
    });
