<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Gateway
    |--------------------------------------------------------------------------
    |
    | The driver name used when calling Gateway::checkout(), Gateway::refund(),
    | etc., without specifying a driver. Override per-call with
    | Gateway::driver('paypal')->...
    |
    */

    'default' => env('PAYMENT_GATEWAY', 'stripe'),

    /*
    |--------------------------------------------------------------------------
    | Webhook Route
    |--------------------------------------------------------------------------
    |
    | Configuration for the package's webhook entrypoint. The route is
    | POST {prefix}/webhook/{gateway} and is registered automatically by
    | the service provider. To disable it (e.g. if you want to wire your
    | own controller), set 'enabled' to false.
    |
    | The 'middleware' option is an array of middleware aliases or class
    | names applied to the route. Webhooks must NOT be behind 'web' (it
    | enables CSRF); 'api' is the common choice, or leave empty.
    |
    */

    'webhook' => [
        'enabled' => true,
        'prefix' => env('PAYMENT_WEBHOOK_PREFIX', 'payment-gateways'),
        'middleware' => ['api'],

        // How long (seconds) a processed webhook id is remembered so
        // redelivered duplicates are dropped before dispatching.
        'idempotency_ttl' => 86400,

        // Queue connection/name for off-request webhook processing. Leave
        // null to use the application defaults; the request always acks fast.
        'connection' => env('PAYMENT_WEBHOOK_QUEUE_CONNECTION'),
        'queue' => env('PAYMENT_WEBHOOK_QUEUE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Gateway Drivers
    |--------------------------------------------------------------------------
    |
    | Each gateway is configured independently. Built-in drivers ship with
    | the core package (stripe, paypal). Additional gateways are added via
    | community plugins (composer require) or by calling Gateway::extend()
    | in your AppServiceProvider::boot().
    |
    */

    'gateways' => [

        'stripe' => [
            'secret' => env('STRIPE_SECRET'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        ],

        'paypal' => [
            'mode' => env('PAYPAL_MODE', 'sandbox'),
            'client_id' => env('PAYPAL_CLIENT_ID'),
            'client_secret' => env('PAYPAL_CLIENT_SECRET'),
            'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
            'default_currency' => env('PAYPAL_DEFAULT_CURRENCY', 'USD'),
        ],

    ],

];
