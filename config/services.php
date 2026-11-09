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

    'lead_api' => [
        'token' => env('LEADS_API_TOKEN'),
    ],

    // Tokens are per project and live encrypted on each unit (unidades.sellflux_token).
    'sellflux' => [
        'base_url' => env('SELLFLUX_BASE_URL', 'https://apis.sellflux.app'),
        'timeout' => (int) env('SELLFLUX_TIMEOUT', 10),
        // The scheduler only runs sellflux:sync-sellers when this is on (D-040).
        'sync_enabled' => (bool) env('SELLFLUX_SYNC_ENABLED', false),
        'sync_limit' => (int) env('SELLFLUX_SYNC_LIMIT', 50),
    ],

];
