<?php

use App\Enums\UserRole;

/**
 * Stripe 課金のプラン定義。設計の正は docs/specs/commercial-stripe-billing.md
 *
 * price ID は環境ごとに変わるので、設定 → 公開販売（未保存時は .env）から流す。
 * 利用者からはプランキー（standard_monthly 等）しか受け取らず、price はここの allowlist から引く。
 */
return [
    'enabled' => (bool) env('BILLING_ENABLED', false),

    /** Checkout / Portal から戻ってくる先 */
    'return_path' => '/mypage/plan',

    'plans' => [
        'standard_monthly' => [
            'label' => 'スタンダード（月額）',
            'price_id' => (string) env('BILLING_PRICE_STANDARD_MONTHLY', ''),
            'interval' => 'month',
            'yen' => (int) env('COMMERCIAL_STANDARD_MONTHLY_YEN', 980),
            'role' => UserRole::Standard->value,
            'trial_days' => (int) env('COMMERCIAL_STANDARD_TRIAL_DAYS', 14),
            'self_serve' => true,
        ],
        'standard_yearly' => [
            'label' => 'スタンダード（年額）',
            'price_id' => (string) env('BILLING_PRICE_STANDARD_YEARLY', ''),
            'interval' => 'year',
            'yen' => (int) env('COMMERCIAL_STANDARD_YEARLY_YEN', 9800),
            'role' => UserRole::Standard->value,
            'trial_days' => (int) env('COMMERCIAL_STANDARD_TRIAL_DAYS', 14),
            'self_serve' => true,
        ],
        // テナント契約は運営が代表アカウントを作る運用なので、画面からは申し込ませない
        'tenant_monthly' => [
            'label' => 'テナント契約（月額）',
            'price_id' => (string) env('BILLING_PRICE_TENANT_MONTHLY', ''),
            'interval' => 'month',
            'yen' => (int) env('COMMERCIAL_TENANT_MONTHLY_YEN', 3980),
            'role' => null,
            'trial_days' => (int) env('COMMERCIAL_TENANT_TRIAL_DAYS', 30),
            'self_serve' => false,
        ],
        'tenant_yearly' => [
            'label' => 'テナント契約（年額）',
            'price_id' => (string) env('BILLING_PRICE_TENANT_YEARLY', ''),
            'interval' => 'year',
            'yen' => (int) env('COMMERCIAL_TENANT_YEARLY_YEN', 43780),
            'role' => null,
            'trial_days' => (int) env('COMMERCIAL_TENANT_TRIAL_DAYS', 30),
            'self_serve' => false,
        ],
    ],

    /** サブスクリプションに item として足すアドオン */
    'addons' => [
        'mailbox_monthly' => [
            'label' => '@sa2-plus.com メールボックス（月額）',
            'price_id' => (string) env('BILLING_PRICE_MAILBOX_MONTHLY', ''),
            'yen' => (int) env('MAIL_ADDON_PRICE_YEN_MONTHLY', 300),
            'grants' => 'mailbox_addon_active',
        ],
        'storage_overage' => [
            'label' => 'ストレージ超過（100GB 単位）',
            'price_id' => (string) env('BILLING_PRICE_STORAGE_OVERAGE', ''),
            'yen' => (int) env('COMMERCIAL_STORAGE_OVERAGE_YEN', 300),
            'grants' => 'storage_overage_active',
            /** 数量1あたりのバイト数（100GB） */
            'unit_bytes' => 100 * 1024 ** 3,
        ],
    ],

    /** 支払い遅延を検知してから有料機能を止めるまでの猶予 */
    'past_due_grace_days' => (int) env('BILLING_PAST_DUE_GRACE_DAYS', 7),
];
