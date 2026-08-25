<?php

/**
 * 専用インスタンス（販路 A）とテナント契約（共有上）の公開単価。
 * 正の説明文は docs/specs/product-direction.md と commercial-tenants.md
 */
return [
    'setup_fee_yen' => (int) env('COMMERCIAL_SETUP_FEE_YEN', 50000),
    'monthly_base_yen' => (int) env('COMMERCIAL_MONTHLY_BASE_YEN', 8000),
    /** 専用インスタンス／テナント契約の月額に含むユーザー数（代表を含む） */
    'included_users' => (int) env('COMMERCIAL_INCLUDED_USERS', 5),
    'extra_user_yen_monthly' => (int) env('COMMERCIAL_EXTRA_USER_YEN', 1000),
    'mailbox_yen_monthly' => (int) env('MAIL_ADDON_PRICE_YEN_MONTHLY', 300),
    'mailbox_yen_yearly' => (int) env('MAIL_ADDON_PRICE_YEN_YEARLY', 3000),
    /** 年一括保守の割引か月数相当（12か月払いで1か月分オフ → 11） */
    'yearly_maintenance_months_charged' => (int) env('COMMERCIAL_YEARLY_MAINTENANCE_MONTHS', 11),
    /** テナント契約の公開月額（5名・メール各1込み）。専用 ¥8,000 より安く出す */
    'tenant_monthly_yen' => (int) env('COMMERCIAL_TENANT_MONTHLY_YEN', 3980),
    /** 0 なら月額×yearly_maintenance_months_charged */
    'tenant_yearly_yen' => (int) env('COMMERCIAL_TENANT_YEARLY_YEN', 0),
    'tenant_trial_days' => (int) env('COMMERCIAL_TENANT_TRIAL_DAYS', 30),
];
