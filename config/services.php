<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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
    
    'paystack' => [
        'secret_key' => env('PAYSTACK_SECRET_KEY'),

        'public_key' => env('PAYSTACK_PUBLIC_KEY'),

        'base_url' => env(
            'PAYSTACK_BASE_URL',
            'https://api.paystack.co'
        ),

        'webhook_secret' => env(
            'PAYSTACK_WEBHOOK_SECRET'
        ),
    ],

    'opay' => [
    'public_key' => env('OPAY_PUBLIC_KEY'),

    'secret_key' => env('OPAY_SECRET_KEY'),

    'merchant_id' => env('OPAY_MERCHANT_ID'),

    'base_url' => env(
        'OPAY_BASE_URL',
        'https://testapi.opaycheckout.com'
    ),

    'country' => env(
        'OPAY_COUNTRY',
        'NG'
    ),

    'currency' => env(
        'OPAY_CURRENCY',
        'NGN'
    ),

    'callback_url' => env('OPAY_CALLBACK_URL'),

    'return_url' => env('OPAY_RETURN_URL'),

    'cancel_url' => env('OPAY_CANCEL_URL'),
],
];
