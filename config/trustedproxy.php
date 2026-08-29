<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | '*' = trust every proxy (local / behind a known edge only).
    | Empty = trust none (Lolipop direct). Comma-separated IPs otherwise.
    |
    | Read via config() from AppServiceProvider — never via env() in
    | bootstrap/app.php, or the value is evaluated before .env is loaded.
    |
    */

    'proxies' => env('TRUSTED_PROXIES'),

];
