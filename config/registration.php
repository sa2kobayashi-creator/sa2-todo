<?php

return [
    /*
    | 新規登録の招待コード（フォールバック）。
    | 管理者が /admin/users で保存した値が優先されます。
    | DB 未設定のときだけ、この env が使われます。空なら登録閉鎖。
    */
    'invite_code' => trim((string) env('REGISTRATION_INVITE_CODE', '')),

    /**
     * TOP 利用申請（/apply）の受付。
     * 公開販売で DB 保存した値が優先。未保存時のみこの env／既定を使う。
     */
    'applications_open' => filter_var(env('REGISTRATION_APPLICATIONS_OPEN', true), FILTER_VALIDATE_BOOL),

    /** TOP 申請の承認リンク有効日数 */
    'application_token_ttl_days' => (int) env('REGISTRATION_APPLICATION_TOKEN_TTL_DAYS', 7),

    /** ライト新規（直近7日）の上限。超えるとライト申請を自動拒否 */
    'light_weekly_cap' => (int) env('REGISTRATION_LIGHT_WEEKLY_CAP', 50),

    /** 利用目的（メッセージ）の最短文字数。短い／空は自動拒否 */
    'purpose_min_length' => (int) env('REGISTRATION_PURPOSE_MIN_LENGTH', 20),

    /** ライト未ログイン警告までの日数（既定 90日＝約3か月） */
    'light_inactive_warn_days' => (int) env('REGISTRATION_LIGHT_INACTIVE_WARN_DAYS', 90),

    /** ライト未ログイン警告後、ログインがなければ削除するまでの猶予日数 */
    'light_inactive_delete_grace_days' => (int) env('REGISTRATION_LIGHT_INACTIVE_DELETE_GRACE_DAYS', 14),

    /** ライトユーザーのご意見メールの宛先 */
    'light_feedback_to' => trim((string) env('LIGHT_FEEDBACK_TO', 'info@sa2-plus.com')),

    /**
     * 捨てアド判定用ドメイン（小文字）。ここに含まれると申請を自動拒否。
     * 必要に応じて追記する。
     */
    'disposable_email_domains' => [
        'mailinator.com', 'guerrillamail.com', 'guerrillamail.de', 'sharklasers.com',
        'grr.la', 'guerrillamailblock.com', 'pokemail.net', 'spam4.me',
        'yopmail.com', 'yopmail.fr', 'cool.fr.nf', 'jetable.org',
        'trashmail.com', 'trashmail.me', 'trashmail.net', 'mytrashmail.com',
        'tempmail.com', 'temp-mail.org', 'temp-mail.io', 'tmpmail.org',
        '10minutemail.com', '10minemail.com', 'minutemail.com',
        'throwawaymail.com', 'getnada.com', 'maildrop.cc', 'discard.email',
        'mailnesia.com', 'fakeinbox.com', 'mailcatch.com', 'inboxkitten.com',
        'moakt.com', 'dispostable.com', 'tempail.com', 'emailondeck.com',
        'mailnull.com', 'spamgourmet.com', 'trash-mail.com', 'wegwerfmail.de',
    ],
];
