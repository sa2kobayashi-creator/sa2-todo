<?php

return [
    'domain' => env('MAIL_DOMAIN', 'sa2-plus.com'),

    /**
     * 無料で持てる @domain 件数。有料オプション化後は 0（申請には mailbox_addon_active が必要）。
     * スタッフ（Admin / SuperAdmin）はアドオン無しでも申請可。
     */
    'free_mailboxes_per_user' => (int) env('MAIL_FREE_MAILBOXES_PER_USER', 0),

    /** 有料オプション契約中に持てる件数（作成済み＋申請中の合算上限） */
    'mailboxes_per_user' => (int) env('MAIL_MAILBOXES_PER_USER', 1),

    /** 表示用の月額・年額（円）。実課金は Stripe 前は請求書＋管理画面フラグ */
    'addon_price_yen_monthly' => (int) env('MAIL_ADDON_PRICE_YEN_MONTHLY', 300),
    'addon_price_yen_yearly' => (int) env('MAIL_ADDON_PRICE_YEN_YEARLY', 3000),

    /** 申請のローカルパート予約語 */
    'reserved_local_parts' => [
        'admin', 'administrator', 'postmaster', 'abuse', 'noreply', 'no-reply',
        'support', 'security', 'root', 'webmaster', 'hostmaster', 'mailer-daemon',
        'info', 'contact', 'sales', 'billing', 'help', 'system',
    ],

    'lolipop' => [
        'imap_host' => 'imap.lolipop.jp',
        'imap_port' => 993,
        'imap_encryption' => 'ssl',
        'smtp_host' => 'smtp.lolipop.jp',
        'smtp_port' => 465,
        'smtp_encryption' => 'ssl',
        'webmail_url' => 'https://mail.lolipop.jp/',
        /** 公開APIのメール作成は近日公開。公開後に接続する */
        'api_mailbox_create_status' => 'coming_soon',
    ],

    'gmail' => [
        'imap_host' => 'imap.gmail.com',
        'imap_port' => 993,
        'imap_encryption' => 'ssl',
        'smtp_host' => 'smtp.gmail.com',
        'smtp_port' => 587,
        'smtp_encryption' => 'tls',
        'help_url' => 'https://support.google.com/accounts/answer/185833',
    ],
];
