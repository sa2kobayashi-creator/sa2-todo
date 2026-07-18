<?php

return [
    'providers' => [
        'openai' => [
            'label' => 'ChatGPT (OpenAI)',
            'models' => [
                'gpt-4o-mini' => 'GPT-4o mini（推奨・安価）',
                'gpt-4o' => 'GPT-4o',
                'gpt-4.1-mini' => 'GPT-4.1 mini',
                'gpt-4.1' => 'GPT-4.1',
            ],
            'default_model' => 'gpt-4o-mini',
            'api_url' => 'https://api.openai.com/v1/chat/completions',
        ],
        'gemini' => [
            'label' => 'Gemini (Google)',
            'models' => [
                'gemini-2.0-flash' => 'Gemini 2.0 Flash（推奨）',
                'gemini-2.0-flash-lite' => 'Gemini 2.0 Flash Lite',
                'gemini-1.5-flash' => 'Gemini 1.5 Flash',
                'gemini-1.5-pro' => 'Gemini 1.5 Pro',
            ],
            'default_model' => 'gemini-2.0-flash',
            'api_url' => 'https://generativelanguage.googleapis.com/v1beta',
        ],
    ],

    'plans' => [
        'free' => '無料枠想定',
        'paid' => '有料',
    ],

    /** 1リクエストあたりの履歴メッセージ上限（system以外） */
    'max_history_messages' => 40,

    /** ユーザーメッセージ最大文字数 */
    'max_user_message_chars' => 8000,
];
