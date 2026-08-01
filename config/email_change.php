<?php

return [
    /** 確認コードの有効時間（分） */
    'code_ttl_minutes' => (int) env('EMAIL_CHANGE_CODE_TTL_MINUTES', 30),

    /** 同じコードに対して許容する入力ミス回数 */
    'max_attempts' => (int) env('EMAIL_CHANGE_MAX_ATTEMPTS', 5),

    /** 再送信を許可するまでの待ち時間（秒） */
    'resend_interval_seconds' => (int) env('EMAIL_CHANGE_RESEND_INTERVAL_SECONDS', 60),
];
