<?php

declare(strict_types=1);

namespace App\Services\Ai\Llm;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * DeepSeek through its OpenAI-compatible chat completions endpoint.
 *
 * Two differences from Gemini shape this class: there is no file attachment
 * (the CSV goes inline in the message) and strict JSON Schema output is not
 * offered, so the schema is spelled out in the prompt and callers keep
 * validating what comes back.
 */
final class DeepSeekLlmClient implements LlmClient
{
    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $timeout,
    ) {}

    public function generate(string $prompt, LlmOptions $options = new LlmOptions): LlmResponse
    {
        if ($this->apiKey === null || $this->apiKey === '') {
            throw new RuntimeException('Invalid API key: DEEPSEEK_API_KEY is not configured.');
        }

        $body = [
            'model' => $this->model,
            'messages' => [['role' => 'user', 'content' => $this->content($prompt, $options)]],
            // Every task here is extraction or classification; reasoning
            // tokens only add latency on screens the user is waiting on.
            'thinking' => ['type' => 'disabled'],
        ];

        if ($options->temperature !== null) {
            $body['temperature'] = $options->temperature;
        }

        if ($options->maxTokens !== null) {
            $body['max_tokens'] = $options->maxTokens;
        }

        if ($options->format === LlmFormat::JsonObject) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        $response = Http::withToken($this->apiKey)
            ->acceptJson()
            ->timeout($this->timeout)
            ->post(rtrim($this->baseUrl, '/').'/chat/completions', $body);

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'DeepSeek HTTP %d: %s',
                $response->status(),
                $response->json('error.message') ?? mb_substr($response->body(), 0, 300),
            ));
        }

        return new LlmResponse(
            text: (string) $response->json('choices.0.message.content', ''),
            truncated: $response->json('choices.0.finish_reason') === 'length',
        );
    }

    private function content(string $prompt, LlmOptions $options): string
    {
        $content = $prompt;

        if ($options->schema !== null) {
            $content .= "\n\nResponde SOLO con un objeto JSON válido que cumpla exactamente este JSON Schema:\n"
                .json_encode($options->schema, JSON_UNESCAPED_UNICODE);
        }

        if ($options->attachment !== null) {
            $content .= "\n\nARCHIVO:\n```\n".$options->attachment."\n```";
        }

        return $content;
    }
}
