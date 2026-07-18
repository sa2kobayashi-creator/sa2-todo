<?php

return [
    /** 1ファイルのアップロード上限（バイト） */
    'max_upload_bytes' => (int) env('PHOTO_MAX_UPLOAD_BYTES', 12 * 1024 * 1024),

    /** ユーザーあたりの保存上限（バイト）。ロリポップ等の共有サーバー向け初期値 500MB */
    'user_quota_bytes' => (int) env('PHOTO_USER_QUOTA_BYTES', 500 * 1024 * 1024),

    /** 原本の長辺上限（px）。超えたら縮小して JPEG 保存 */
    'max_long_edge' => (int) env('PHOTO_MAX_LONG_EDGE', 1920),

    /** サムネイル長辺（px） */
    'thumb_long_edge' => (int) env('PHOTO_THUMB_LONG_EDGE', 720),

    /** JPEG 品質 0–100 */
    'jpeg_quality' => (int) env('PHOTO_JPEG_QUALITY', 82),

    /**
     * 写真ファイルの保存ディスク。
     * - public: サーバーローカル（開発・ロリポップ直保存）
     * - r2: Cloudflare R2（S3互換）
     */
    'disk' => env('PHOTO_DISK', 'public'),
];
