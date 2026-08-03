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

    'google_maps' => [
        'api_key' => env('GOOGLE_MAPS_API_KEY'),
    ],

    /*
    | Google Calendar OAuth（ログイン認証ではない。カレンダー連携専用）
    | redirect 未設定時は APP_URL + /auth/google/calendar/callback
    */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    /*
    | LINE Messaging API（通知連携。ログイン認証ではない）
    | Webhook: {APP_URL}/webhooks/line
    */
    'line' => [
        'channel_access_token' => env('LINE_CHANNEL_ACCESS_TOKEN'),
        'channel_secret' => env('LINE_CHANNEL_SECRET'),
        'bot_basic_id' => env('LINE_BOT_BASIC_ID'),
    ],

    /*
    | Facebook Page / Messenger（通知連携）
    | Webhook: {APP_URL}/webhooks/messenger
    */
    'facebook' => [
        'app_id' => env('FACEBOOK_APP_ID'),
        'app_secret' => env('FACEBOOK_APP_SECRET'),
        'page_access_token' => env('FACEBOOK_PAGE_ACCESS_TOKEN'),
        'verify_token' => env('FACEBOOK_VERIFY_TOKEN'),
        'page_name' => env('FACEBOOK_PAGE_NAME'),
    ],

    'translation' => [
        'provider' => env('TRANSLATION_PROVIDER', 'deepl'),
        'api_key' => env('TRANSLATION_API_KEY'),
        'api_url' => env('TRANSLATION_API_URL', 'https://api-free.deepl.com/v2/translate'),
        'cache_ttl' => (int) env('TRANSLATION_CACHE_TTL', 86400),
    ],

    'travelpayouts' => [
        'token' => env('TRAVELPAYOUTS_TOKEN'),
        'project_id' => env('TRAVELPAYOUTS_PROJECT_ID', env('TRAVELPAYOUTS_MARKER')),
        'base_url' => env('TRAVELPAYOUTS_BASE_URL', 'https://api.travelpayouts.com'),
        'market_php' => env('TRAVELPAYOUTS_MARKET_PHP', 'ph'),
        'market_jpy' => env('TRAVELPAYOUTS_MARKET_JPY', 'jp'),
        'prefer_airline' => env('TRAVELPAYOUTS_PREFER_AIRLINE', '5J'),
        'direct_only' => filter_var(env('TRAVELPAYOUTS_DIRECT_ONLY', false), FILTER_VALIDATE_BOOLEAN),
    ],

];
