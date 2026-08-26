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

    /*
    | LiveKit Cloud / self-hosted LiveKit（メッセージDM通話）
    | 通常は設定画面（外部連携）で保存。未設定時のみ .env を使う。
    | URL は wss:// 形式。API シークレットはサーバーだけで使用する。
    */
    'livekit' => [
        'url' => env('LIVEKIT_URL'),
        'api_key' => env('LIVEKIT_API_KEY'),
        'api_secret' => env('LIVEKIT_API_SECRET'),
    ],

    /*
    | Web Push / Android TWA 通知委任
    | Firebase Console の Web Push 証明書で生成した VAPID 鍵を利用する。
    */
    'web_push' => [
        'subject' => env('WEB_PUSH_VAPID_SUBJECT'),
        'public_key' => env('WEB_PUSH_VAPID_PUBLIC_KEY'),
        'private_key' => env('WEB_PUSH_VAPID_PRIVATE_KEY'),
    ],

    'translation' => [
        'provider' => env('TRANSLATION_PROVIDER', 'deepl'),
        'api_key' => env('TRANSLATION_API_KEY'),
        'api_url' => env('TRANSLATION_API_URL', 'https://api-free.deepl.com/v2/translate'),
        'cache_ttl' => (int) env('TRANSLATION_CACHE_TTL', 86400),
    ],

    'cloudflare_workers_ai' => [
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
        'api_token' => env('CLOUDFLARE_WORKERS_AI_TOKEN'),
        'model' => env('CLOUDFLARE_WORKERS_AI_MODEL', '@cf/meta/llama-3.1-8b-instruct-fp8'),
        'timeout' => (int) env('CLOUDFLARE_WORKERS_AI_TIMEOUT', 45),
    ],

    'travelpayouts' => [
        'token' => env('TRAVELPAYOUTS_TOKEN'),
        'project_id' => env('TRAVELPAYOUTS_PROJECT_ID', env('TRAVELPAYOUTS_MARKER')),
        'base_url' => env('TRAVELPAYOUTS_BASE_URL', 'https://api.travelpayouts.com'),
        'market_php' => env('TRAVELPAYOUTS_MARKET_PHP', 'ph'),
        'market_jpy' => env('TRAVELPAYOUTS_MARKET_JPY', 'jp'),
        'prefer_airline' => env('TRAVELPAYOUTS_PREFER_AIRLINE', ''),
        'direct_only' => filter_var(env('TRAVELPAYOUTS_DIRECT_ONLY', false), FILTER_VALIDATE_BOOLEAN),
    ],

    // Google Maps Routes API（路線検索）。通常は 設定 → API設定 で保存し、DB 未設定のときだけここが使われる。
    'google_routes' => [
        'api_key' => env('GOOGLE_ROUTES_API_KEY'),
        'timeout' => (int) env('GOOGLE_ROUTES_TIMEOUT', 20),
    ],

    // 駅すぱあと（路線検索）。通常は 設定 → API設定 で保存し、DB 未設定のときだけここが使われる。
    'ekispert' => [
        'api_key' => env('EKISPERT_API_KEY'),
        'base_url' => env('EKISPERT_BASE_URL', 'https://api.ekispert.jp/v1/json'),
        'timeout' => (int) env('EKISPERT_TIMEOUT', 20),
    ],

    // NAVITIME API（路線検索）。通常は 設定 → API設定 で保存し、DB 未設定のときだけここが使われる。
    'navitime' => [
        'mode' => env('NAVITIME_MODE', 'rapidapi'),
        'api_key' => env('NAVITIME_API_KEY'),
        'route_host' => env('NAVITIME_ROUTE_HOST', 'navitime-route-totalnavi.p.rapidapi.com'),
        'node_host' => env('NAVITIME_NODE_HOST', ''),
        'base_url' => env('NAVITIME_BASE_URL', ''),
        'auth_header' => env('NAVITIME_AUTH_HEADER', 'x-api-key'),
        'timeout' => (int) env('NAVITIME_TIMEOUT', 20),
    ],

];
