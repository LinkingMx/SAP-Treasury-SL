<?php

namespace App\Providers;

use App\Services\Ai\Llm\DeepSeekLlmClient;
use App\Services\Ai\Llm\GeminiLlmClient;
use App\Services\Ai\Llm\LlmClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LlmClient::class, fn (): LlmClient => match (config('ai.driver')) {
            'deepseek' => new DeepSeekLlmClient(
                apiKey: config('ai.deepseek.api_key'),
                baseUrl: (string) config('ai.deepseek.base_url'),
                model: (string) config('ai.deepseek.model'),
                timeout: (int) config('ai.deepseek.timeout'),
            ),
            'gemini' => new GeminiLlmClient,
            default => throw new \InvalidArgumentException(sprintf(
                'AI_DRIVER "%s" no es válido. Usa "deepseek" o "gemini".',
                config('ai.driver'),
            )),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
