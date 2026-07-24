<?php

return [
    /**
     * DeepL API Pro の月額基本料金目安（EUR）。
     * 公式プラン変更に合わせて設定画面から変更可能。
     */
    'paid_monthly_base_eur' => (float) env('DEEPL_PAID_MONTHLY_BASE_EUR', 5.49),

    /**
     * DeepL API Pro の従量単価目安（EUR / 100万文字）。
     * 文字単価 = この値 / 1_000_000
     */
    'paid_per_million_chars_eur' => (float) env('DEEPL_PAID_PER_MILLION_CHARS_EUR', 20.0),

    /** Free プランの月間文字上限（目安） */
    'free_monthly_character_limit' => (int) env('DEEPL_FREE_MONTHLY_CHARACTER_LIMIT', 500_000),
];
