<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'currency' => env('STRIPE_CURRENCY', 'bdt'),
        'success_url' => env('STRIPE_SUCCESS_URL', env('APP_URL').'/citizen/taxes/payment/success?session_id={CHECKOUT_SESSION_ID}'),
        'cancel_url' => env('STRIPE_CANCEL_URL', env('APP_URL').'/citizen/taxes'),
    ],

    /*
    |--------------------------------------------------------------------------
    | SSL Commerz (Bangladesh online payment gateway)
    |--------------------------------------------------------------------------
    |
    | SSL Commerz is a hosted checkout for BDT payments. We use the Session
    | API (sessiontoken) to start a transaction, then accept four return
    | callbacks from SSLCZ:
    |   - success_url  (customer paid)
    |   - fail_url     (customer's payment method was declined)
    |   - cancel_url   (customer bailed out)
    |   - ipn_url      (server-to-server confirmation, always trust this)
    |
    | sandbox=true hits the sandbox host; production hosts are .com and
    | .com.bd. Set sandbox=false when going live.
    */

    'sslcommerz' => [
        'store_id' => env('SSLCZ_STORE_ID'),
        'store_passwd' => env('SSLCZ_STORE_PASSWORD'),
        'sandbox' => env('SSLCZ_SANDBOX', true),
        'currency' => env('SSLCZ_CURRENCY', 'BDT'),
        'success_url' => env('SSLCZ_SUCCESS_URL', env('APP_URL').'/citizen/taxes/payment/success'),
        'fail_url' => env('SSLCZ_FAIL_URL', env('APP_URL').'/citizen/taxes/payment/fail'),
        'cancel_url' => env('SSLCZ_CANCEL_URL', env('APP_URL').'/citizen/taxes/payment/cancel'),
        'ipn_url' => env('SSLCZ_IPN_URL', env('APP_URL').'/citizen/taxes/payment/ipn'),
        'connect_timeout' => env('SSLCZ_CONNECT_TIMEOUT', 30),

        /*
         * Demo mode. When true, the controller bypasses the SSLCZ
         * sandbox entirely: it generates a fake session, redirects the
         * citizen to an in-app confirmation page, and records the
         * payment locally. No money moves and no external API call is
         * made. Useful for local development, demos, and CI when
         * sandbox credentials are not (yet) configured.
         *
         * Demo mode is automatically enabled when SSLCZ credentials are
         * missing or contain the placeholder values shipped with the
         * repo; explicit SSLCZ_DEMO_MODE=true forces it on regardless
         * of credentials.
         */
        'demo_mode' => env('SSLCZ_DEMO_MODE', false),

        /*
         * Shared secret for the unauthenticated IPN endpoint.
         *
         * The IPN is called by SSL Commerz's servers, so it cannot sit
         * behind the session-based `auth` middleware. To keep the
         * endpoint from being a free-for-all, we require the gateway to
         * send this secret in the `X-SSLCommerz-Secret` header. Leave it
         * empty to disable the header check (the controller still
         * reconciles every callback against the gateway's Validation
         * API before recording a payment).
         */
        'ipn_secret' => env('SSLCZ_IPN_SECRET', ''),
    ],

];
