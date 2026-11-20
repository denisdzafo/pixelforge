<?php

return [

    'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),
    'api_key' => env('PIXELFORGE_API_KEY'),
    'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),

    'webhook_tolerance_seconds' => 300,

    'http' => [
        'timeout' => (int) env('PIXELFORGE_TIMEOUT', 20),
        'connect_timeout' => 5,
    ],

    'markup_percent' => 25,

    'limits' => [
        'requests_per_minute' => 50,
        'concurrent_generations' => 3,
    ],

    'submit' => [
        'max_attempts' => 8,
        'backoff_base_seconds' => 2,
        'backoff_max_seconds' => 60,
        'give_up_after_minutes' => 30,
        'slot_wait_seconds' => 5,
    ],

    'reconcile' => [
        'stale_after_seconds' => 60,
        'batch_size' => 15,
    ],

    'cache' => [
        'pricing_seconds' => 3600,
        'usage_seconds' => 60,
    ],

    'credits' => [
        'signup_bonus_usd' => env('PIXELFORGE_SIGNUP_BONUS_USD', '1.00'),
        'packs' => ['5.00', '10.00', '25.00'],
    ],

    'fallback_pricing' => [
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'tokens' => [
            'characters_per_text_token' => 4,
            'text_token_variance' => '0.15',
            'per_reference_image' => 765,
            'output' => [
                'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
            ],
            'reasoning' => [
                'low' => ['min' => 0, 'max' => 500],
                'medium' => ['min' => 200, 'max' => 2000],
                'high' => ['min' => 1000, 'max' => 8000],
            ],
        ],
    ],
];
