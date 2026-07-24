<?php

return [
    /** Google Cloud の YouTube Data API v3 キー（設定画面でも上書き可） */
    'data_api_key' => (string) env('YOUTUBE_DATA_API_KEY', ''),

    /** 検索1回あたりの件数 */
    'search_max_results' => (int) env('YOUTUBE_SEARCH_MAX_RESULTS', 12),

    'search_region_code' => (string) env('YOUTUBE_SEARCH_REGION', 'JP'),

    'search_relevance_language' => (string) env('YOUTUBE_SEARCH_LANG', 'ja'),
];
