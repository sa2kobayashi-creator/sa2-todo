<?php

return [
    'domain' => env('MAIL_DOMAIN', 'sa2-plus.com'),

    /** 契約（ユーザー）あたりの無料 @domain 枠 */
    'free_mailboxes_per_user' => 1,

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
        'smtp_port' => 465,
        'smtp_encryption' => 'ssl',
        'help_url' => 'https://support.google.com/accounts/answer/185833',
    ],
];
