<?php

return [
    /*
    | ユーザー別・日次の利用上限（スーパー管理者の試作機能も含む）
    | キー単位の DeepL 日次枠（translation_api_keys）とは別段。
    */
    'translate_chars_per_day' => (int) env('USER_TRANSLATE_CHARS_PER_DAY', 50_000),
    'llm_voice_requests_per_day' => (int) env('USER_LLM_VOICE_REQUESTS_PER_DAY', 30),
    'enhance_requests_per_day' => (int) env('USER_ENHANCE_REQUESTS_PER_DAY', 10),
    'workers_ai_requests_per_day' => (int) env('USER_WORKERS_AI_REQUESTS_PER_DAY', 20),
];
