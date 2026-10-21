<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | AI Provider
    |--------------------------------------------------------------------------
    |
    | Which provider answers every AI feature: "deepseek" or "gemini". Gemini
    | went down four times (dunning, denied access, suspension, depleted
    | credits), so the provider is a switch in the environment: when one
    | fails, production moves to the other without shipping a release.
    |
    | The default stays on "gemini" so deploying this code changes nothing
    | until the environment explicitly opts into DeepSeek with its key.
    */

    'driver' => env('AI_DRIVER', 'gemini'),

    'deepseek' => [
        'api_key' => env('DEEPSEEK_API_KEY'),
        'base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com'),
        'model' => env('DEEPSEEK_MODEL', 'deepseek-flash'),
        'timeout' => (int) env('DEEPSEEK_TIMEOUT', 120),
    ],
];
