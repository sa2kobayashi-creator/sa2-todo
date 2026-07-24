<?php

return [
    /** 音声1ファイルのアップロード上限（バイト）。初期値 100MB */
    'max_upload_bytes' => (int) env('MUSIC_MAX_UPLOAD_BYTES', 100 * 1024 * 1024),

    /** 保存ディスク（未指定時は photos.disk） */
    'disk' => env('MUSIC_DISK', env('PHOTO_DISK', 'public')),

    'allowed_mimes' => [
        'audio/mpeg',
        'audio/mp3',
        'audio/mp4',
        'audio/x-m4a',
        'audio/m4a',
        'audio/aac',
        'audio/wav',
        'audio/x-wav',
        'audio/ogg',
        'audio/webm',
    ],

    'allowed_extensions' => ['mp3', 'm4a', 'aac', 'wav', 'ogg', 'webm'],
];
