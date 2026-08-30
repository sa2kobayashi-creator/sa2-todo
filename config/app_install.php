<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Android APK
    |--------------------------------------------------------------------------
    |
    | 外部URL（https://...）を ANDROID_APK_URL に入れるか、
    | public/downloads/sa2-plus.apk を置く（ANDROID_APK_PATH）。
    | どちらも無いときはダッシュボードの APK リンクを出さない。
    |
    */

    'android_apk_url' => env('ANDROID_APK_URL', ''),

    'android_apk_path' => env('ANDROID_APK_PATH', 'downloads/sa2-plus.apk'),

];
