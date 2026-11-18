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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
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

    'simulated_payment' => [
        'lease_seconds' => (int) env('ORDER_PROCESSING_LEASE_SECONDS', 30),
        'force_outcome' => env('SIMULATED_PAYMENT_FORCE_OUTCOME'),
        'refund_force_outcome' => env('SIMULATED_REFUND_FORCE_OUTCOME', 'success'),
        'fail_before_persist' => filter_var(
            env('SIMULATED_PAYMENT_FAIL_BEFORE_PERSIST', false),
            FILTER_VALIDATE_BOOL
        ),
        'timeout_after_persist' => filter_var(
            env('SIMULATED_PAYMENT_TIMEOUT_AFTER_PERSIST', false),
            FILTER_VALIDATE_BOOL
        ),
    ],

];
