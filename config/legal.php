<?php

/**
 * 特定商取引法に基づく表記・プライバシーポリシーの事業者情報。
 *
 * 個人事業主が有料販売する場合、氏名・住所・連絡先の表示は原則として必須。
 * 値は設定 → 公開販売 が正。未保存のときは本番 .env の LEGAL_* を使う。
 * リポジトリには実データを置かない。未設定の項目は画面に「未設定」と出る。
 */
return [
    /** 販売事業者名。個人事業主は原則として屋号ではなく本名 */
    'operator_name' => (string) env('LEGAL_OPERATOR_NAME', ''),
    /** 屋号（任意。本名と併記する） */
    'operator_trade_name' => (string) env('LEGAL_OPERATOR_TRADE_NAME', ''),
    /** 運営統括責任者 */
    'operator_manager' => (string) env('LEGAL_OPERATOR_MANAGER', ''),
    /** 所在地（都道府県から番地まで） */
    'address' => (string) env('LEGAL_ADDRESS', ''),
    /** 電話番号。請求があれば遅滞なく開示する運用でも、番号自体は用意しておく */
    'phone' => (string) env('LEGAL_PHONE', ''),
    'phone_hours' => (string) env('LEGAL_PHONE_HOURS', ''),
    /** 問い合わせ先メール。未ログインの人が到達できる唯一の窓口になる */
    'contact_email' => (string) env('LEGAL_CONTACT_EMAIL', ''),
    /** 個人情報の開示等請求の窓口。空なら contact_email を使う */
    'privacy_contact_email' => (string) env('LEGAL_PRIVACY_CONTACT_EMAIL', ''),
    /** 適格請求書発行事業者の登録番号（課税事業者になったら設定） */
    'invoice_registration_number' => (string) env('LEGAL_INVOICE_NUMBER', ''),

    /**
     * 個人データの取扱いを委託する外部サービス。
     * プライバシーポリシーの「委託先」「越境移転」に出す。
     */
    'processors' => [
        ['name' => 'Cloudflare, Inc.', 'country' => '米国', 'purpose' => '写真・動画などのファイル保管（R2）'],
        ['name' => 'Backblaze, Inc.', 'country' => '米国', 'purpose' => 'ファイルの長期保管・バックアップ（B2）'],
        ['name' => 'Cloudinary Ltd.', 'country' => 'イスラエル・米国', 'purpose' => '画像の変換・編集'],
        ['name' => 'Google LLC', 'country' => '米国', 'purpose' => 'カレンダー連携、地図・経路、動画検索'],
        ['name' => 'OpenAI, L.L.C.', 'country' => '米国', 'purpose' => '音声・文章の AI 変換（利用時のみ）'],
        ['name' => 'DeepL SE', 'country' => 'ドイツ', 'purpose' => '翻訳（利用時のみ）'],
        ['name' => 'LINEヤフー株式会社', 'country' => '日本', 'purpose' => 'LINE 通知の送信（連携時のみ）'],
        ['name' => 'Meta Platforms, Inc.', 'country' => '米国', 'purpose' => 'Messenger 通知の送信（連携時のみ）'],
        ['name' => 'Stripe, Inc.', 'country' => '米国', 'purpose' => 'クレジットカード決済（有料プラン契約時のみ）'],
    ],
];
