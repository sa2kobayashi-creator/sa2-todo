<?php

return [
    /*
    | 未保存時のフォールバック（全員同じ日次）。プラン別は usage_limit_policies。
    | キー単位の DeepL 日次枠（translation_api_keys）とは別段。
    | 正: docs/specs/commercial-usage-limits.md
    */
    'translate_chars_per_day' => (int) env('USER_TRANSLATE_CHARS_PER_DAY', 50_000),
    'llm_voice_requests_per_day' => (int) env('USER_LLM_VOICE_REQUESTS_PER_DAY', 30),
    'workers_ai_requests_per_day' => (int) env('USER_WORKERS_AI_REQUESTS_PER_DAY', 20),

    'yen_per_llm_voice' => (int) env('USAGE_YEN_PER_LLM_VOICE', 5),
    'yen_per_workers_ai' => (int) env('USAGE_YEN_PER_WORKERS_AI', 3),
    'yen_per_translate_1000' => (int) env('USAGE_YEN_PER_TRANSLATE_1000', 2),
];
