<?php

return [
    /*
    | ロリポップ / R2 / B2 の容量しきい値（初期値。実測後に調整する）。
    | 本文は docs/specs/storage-management.md
    */
    'r2_warn_bytes' => (int) env('STORAGE_R2_WARN_BYTES', 8 * 1024 * 1024 * 1024),
    'r2_archive_bytes' => (int) env('STORAGE_R2_ARCHIVE_BYTES', 9 * 1024 * 1024 * 1024),
    'r2_cap_bytes' => (int) env('STORAGE_R2_CAP_BYTES', 10 * 1024 * 1024 * 1024),

    'mail_warn_bytes' => (int) env('STORAGE_MAIL_WARN_BYTES', 700 * 1024 * 1024),
    'mail_archive_bytes' => (int) env('STORAGE_MAIL_ARCHIVE_BYTES', 800 * 1024 * 1024),
    'mail_target_bytes' => (int) env('STORAGE_MAIL_TARGET_BYTES', 600 * 1024 * 1024),
    'mail_protect_days' => (int) env('STORAGE_MAIL_PROTECT_DAYS', 90),
    'mail_batch' => (int) env('STORAGE_MAIL_BATCH', 5),
    'mail_accounts_per_run' => (int) env('STORAGE_MAIL_ACCOUNTS_PER_RUN', 2),

    'db_warn_bytes' => (int) env('STORAGE_DB_WARN_BYTES', 300 * 1024 * 1024),
    'db_archive_bytes' => (int) env('STORAGE_DB_ARCHIVE_BYTES', 500 * 1024 * 1024),
    'db_min_age_days' => (int) env('STORAGE_DB_MIN_AGE_DAYS', 365),
    'db_batch' => (int) env('STORAGE_DB_BATCH', 20),

    'backup_keep_days' => (int) env('STORAGE_DB_BACKUP_KEEP_DAYS', 14),
    'backup_timeout' => (int) env('STORAGE_DB_BACKUP_TIMEOUT', 900),
    'backup_driver' => env('STORAGE_DB_BACKUP_DRIVER', 'mysqldump'), // mysqldump|php
    'mysqldump_path' => env('STORAGE_MYSQLDUMP_PATH', 'mysqldump'),

    'disk' => env('STORAGE_ARCHIVE_DISK', 'backblaze'),
    'max_consecutive_errors' => 3,
];
