<?php

return [
    /** 画像1ファイルのアップロード上限（バイト）。0 = アプリ側上限なし */
    'max_upload_bytes' => (int) env('PHOTO_MAX_UPLOAD_BYTES', 0),

    /** 動画1ファイルのアップロード上限（バイト）。初期値 800MB */
    'max_video_upload_bytes' => (int) env('PHOTO_MAX_VIDEO_UPLOAD_BYTES', 800 * 1024 * 1024),

    /** ffmpeg 実行ファイル（PATH 上の名前、または絶対パス） */
    'ffmpeg_path' => env('FFMPEG_PATH', 'ffmpeg'),

    /** ユーザーあたりの無料枠目安（バイト）。Cloudflare R2 無料枠相当の 10GB（ホット原本） */
    'user_quota_bytes' => (int) env('PHOTO_USER_QUOTA_BYTES', 10 * 1024 * 1024 * 1024),

    /** R2 無料枠超過時のストレージ単価目安（USD / GB / 月） */
    'overage_price_per_gb_month_usd' => (float) env('PHOTO_OVERAGE_PRICE_PER_GB_MONTH_USD', 0.015),

    /** Backblaze B2 無料枠目安（バイト）。公式の常時無料 10GB */
    'b2_quota_bytes' => (int) env('PHOTO_B2_QUOTA_BYTES', 10 * 1024 * 1024 * 1024),

    /** B2 無料枠超過時のストレージ単価目安（USD / GB / 月）。約 $6.95/TB */
    'b2_overage_price_per_gb_month_usd' => (float) env('PHOTO_B2_OVERAGE_PRICE_PER_GB_MONTH_USD', 0.006),

    /** Cloudinary Free プランの月間クレジット（ストレージ・帯域・変換の合算） */
    'cloudinary_free_credits' => (int) env('PHOTO_CLOUDINARY_FREE_CREDITS', 25),

    /**
     * 原本の長辺上限（px）。0 = 解像度を変更せず原本のまま保存。
     * （互換のため残置。現行実装では未使用）
     */
    'max_long_edge' => (int) env('PHOTO_MAX_LONG_EDGE', 0),

    /** サムネイル長辺（px） */
    'thumb_long_edge' => (int) env('PHOTO_THUMB_LONG_EDGE', 720),

    /** JPEG 品質 0–100（サムネイル用） */
    'jpeg_quality' => (int) env('PHOTO_JPEG_QUALITY', 82),

    /**
     * 写真ファイルの保存ディスク。
     * - public: サーバーローカル（開発・ロリポップ直保存）
     * - r2: Cloudflare R2（S3互換）
     * 設定メニューのパイプラインで上書き可能。
     */
    'disk' => env('PHOTO_DISK', 'public'),

    /** Cloudinary（表示用変換）。詳細は設定メニュー / media_storage_settings */
    'cloudinary' => [
        'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
        'api_key' => env('CLOUDINARY_API_KEY'),
        'api_secret' => env('CLOUDINARY_API_SECRET'),
        'folder' => env('CLOUDINARY_FOLDER', 'sa2todo'),
    ],
];
