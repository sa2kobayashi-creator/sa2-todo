<?php

return [
    /** 画像1ファイルのアップロード上限（バイト）。0 = アプリ側上限なし */
    'max_upload_bytes' => (int) env('PHOTO_MAX_UPLOAD_BYTES', 0),

    /** 動画1ファイルのアップロード上限（バイト）。初期値 1GB */
    'max_video_upload_bytes' => (int) env('PHOTO_MAX_VIDEO_UPLOAD_BYTES', 1024 * 1024 * 1024),

    /** ffmpeg 実行ファイル（PATH 上の名前、または絶対パス） */
    'ffmpeg_path' => env('FFMPEG_PATH', 'ffmpeg'),

    /** ユーザーあたりの無料枠（合計バイト）。R2+B2 合算の製品無料枠。既定 20GB */
    'user_free_quota_bytes' => (int) env('PHOTO_USER_FREE_QUOTA_BYTES', 20 * 1024 * 1024 * 1024),

    /**
     * 保存容量バーの表示上限（バイト）。使用量グラフの「満杯」基準。既定 1TiB。
     * 無料枠（user_free_quota_bytes）とは別。アップロード可否は無料枠側で判定する。
     */
    'storage_display_capacity_bytes' => (int) env(
        'PHOTO_STORAGE_DISPLAY_CAPACITY_BYTES',
        1024 * 1024 * 1024 * 1024
    ),

    /**
     * アップロード時に同期で行う R2→B2 アーカイブの最大件数。
     * 大きいほど R2 を抑えやすいが保存が遅くなる。大量移動は cron（photos:archive-cold）へ。
     */
    'upload_sync_archive_limit' => (int) env('PHOTO_UPLOAD_SYNC_ARCHIVE_LIMIT', 0),

    /**
     * Photos「B2へアーカイブ」1リクエストあたりの処理件数。
     * クライアントは完了までこの件数ずつ繰り返す（プロキシ/PHPタイムアウト回避）。
     */
    'archive_cold_batch_size' => max(1, min(200, (int) env('PHOTO_ARCHIVE_COLD_BATCH_SIZE', 40))),

    /**
     * 「B2へアーカイブ」1リクエストのタイムアウト（秒）。PHP set_time_limit とブラウザ側に反映。
     * リバースプロキシのタイムアウトより短くすること。
     */
    'archive_cold_request_timeout_seconds' => max(60, (int) env('PHOTO_ARCHIVE_COLD_TIMEOUT_SECONDS', 900)),

    /** 「B2へアーカイブ」手動実行の最大バッチ回数（総件数 ≒ batch_size × max_batches） */
    'archive_cold_max_batches' => max(1, min(200, (int) env('PHOTO_ARCHIVE_COLD_MAX_BATCHES', 50))),

    /** ホット（R2 等）側のソフト上限目安（バイト）。長期保存へ移す閾値。既定 10GB */
    'user_quota_bytes' => (int) env('PHOTO_USER_QUOTA_BYTES', 10 * 1024 * 1024 * 1024),

    /** R2 無料枠超過時のストレージ単価目安（USD / GB / 月）※見込表示・将来の実課金単価のたたき台 */
    'overage_price_per_gb_month_usd' => (float) env('PHOTO_OVERAGE_PRICE_PER_GB_MONTH_USD', 0.015),

    /** Backblaze B2 側のソフト上限目安（バイト）。既定 10GB */
    'b2_quota_bytes' => (int) env('PHOTO_B2_QUOTA_BYTES', 10 * 1024 * 1024 * 1024),

    /**
     * 無料枠超過後のアップロードを許可するか（将来の Stripe 等・有料プラン）。
     * false の間は超過すると新規追加を止める（見込料金は表示のみ）。
     */
    'paid_overage_enabled' => (bool) env('PHOTO_PAID_OVERAGE_ENABLED', false),

    /** 無料枠超過かつ有料プラン未契約のとき、新規アップロードを拒否する */
    'block_uploads_over_free_quota' => (bool) env('PHOTO_BLOCK_UPLOADS_OVER_FREE_QUOTA', true),

    /** B2 無料枠超過時のストレージ単価目安（USD / GB / 月）。約 $6.95/TB */
    'b2_overage_price_per_gb_month_usd' => (float) env('PHOTO_B2_OVERAGE_PRICE_PER_GB_MONTH_USD', 0.006),

    /**
     * B2 転送（egress）単価（USD / GB）。保管量の N 倍まで無料、超過分に適用。
     * アプリ経由の原本配信は CDN 経由ではないため、通常は課金対象になり得る。
     */
    'b2_egress_price_per_gb_usd' => (float) env('PHOTO_B2_EGRESS_PRICE_PER_GB_USD', 0.01),

    /** B2 無料転送枠 = 当月保管量 × この倍率（公式は平均保管量の約 3 倍） */
    'b2_free_egress_storage_multiplier' => (float) env('PHOTO_B2_FREE_EGRESS_STORAGE_MULTIPLIER', 3),

    /**
     * B2 Class B（ダウンロード等）10,000 回あたりの単価（USD）。
     * 2025-05 以降の従量プランでは Class A/B/C は無料のため既定 0。
     */
    'b2_class_b_price_per_10k_usd' => (float) env('PHOTO_B2_CLASS_B_PRICE_PER_10K_USD', 0),

    /** Class B の日次無料枠（単価が 0 より大きい場合のみ使用） */
    'b2_class_b_free_per_day' => (int) env('PHOTO_B2_CLASS_B_FREE_PER_DAY', 2500),

    /** B2 Class A（アップロード等）10,000 回あたりの単価（USD）。現行は無料のため既定 0 */
    'b2_class_a_price_per_10k_usd' => (float) env('PHOTO_B2_CLASS_A_PRICE_PER_10K_USD', 0),

    /** 料金シミュレーション用 USD→JPY フォールバック（取得失敗時） */
    'usd_to_jpy_fallback' => (float) env('PHOTO_USD_TO_JPY_FALLBACK', 150),

    /** Cloudinary Free プランの月間クレジット（ストレージ・帯域・変換の合算） */
    'cloudinary_free_credits' => (int) env('PHOTO_CLOUDINARY_FREE_CREDITS', 25),

    /** Stability AI 1リクエストあたりの最大総ピクセル（API 上限 1,048,576）。超過時は縮小せずタイル分割 */
    'stability_max_input_pixels' => (int) env('PHOTO_STABILITY_MAX_INPUT_PIXELS', 1_048_576),

    /**
     * 鮮明化結果の最大総ピクセル。API は最大約4倍まで上げるが、巨大化を抑える上限。
     * （以前はタイル結果を元解像度へ戻しており、見た目の改善がほぼ失われていた）
     */
    'stability_max_output_pixels' => (int) env('PHOTO_STABILITY_MAX_OUTPUT_PIXELS', 16_777_216),

    /** 鮮明化結果の長辺上限（px） */
    'stability_max_output_edge' => (int) env('PHOTO_STABILITY_MAX_OUTPUT_EDGE', 8192),

    /**
     * Real-ESRGAN ncnn-vulkan 実行ファイルの既定パス。
     * 設定画面のパスが空のときに参照する。
     */
    'realesrgan_binary' => env('REALESRGAN_BINARY', storage_path('app/bin/realesrgan-ncnn-vulkan'.(PHP_OS_FAMILY === 'Windows' ? '.exe' : ''))),

    /** Real-ESRGAN 入力の長辺上限（px）。低VRAM向けに事前縮小する */
    'realesrgan_max_input_edge' => (int) env('REALESRGAN_MAX_INPUT_EDGE', 1024),

    /** Real-ESRGAN 1回あたりのタイムアウト（秒） */
    'realesrgan_timeout' => (int) env('REALESRGAN_TIMEOUT', 600),

    /** SwinIR GPU VPS API の既定タイムアウト（秒） */
    'swinir_timeout' => (int) env('SWINIR_TIMEOUT', 600),

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
