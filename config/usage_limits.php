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
    'route_search_requests_per_day' => (int) env('USER_ROUTE_SEARCH_REQUESTS_PER_DAY', 30),
    'youtube_requests_per_day' => (int) env('USER_YOUTUBE_REQUESTS_PER_DAY', 20),
    'cloudinary_requests_per_day' => (int) env('USER_CLOUDINARY_REQUESTS_PER_DAY', 10),
    'livekit_requests_per_day' => (int) env('USER_LIVEKIT_REQUESTS_PER_DAY', 10),

    'yen_per_llm_voice' => (int) env('USAGE_YEN_PER_LLM_VOICE', 5),
    'yen_per_workers_ai' => (int) env('USAGE_YEN_PER_WORKERS_AI', 3),
    'yen_per_translate_1000' => (int) env('USAGE_YEN_PER_TRANSLATE_1000', 2),
    'yen_per_route_search' => (int) env('USAGE_YEN_PER_ROUTE_SEARCH', 4),
    'yen_per_youtube' => (int) env('USAGE_YEN_PER_YOUTUBE', 2),
    'yen_per_cloudinary' => (int) env('USAGE_YEN_PER_CLOUDINARY', 5),
    'yen_per_livekit' => (int) env('USAGE_YEN_PER_LIVEKIT', 8),
];
