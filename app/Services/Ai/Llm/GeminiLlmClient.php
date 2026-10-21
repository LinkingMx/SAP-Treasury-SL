<?php

declare(strict_types=1);

namespace App\Services\Ai\Llm;

use App\Services\Ai\GeminiModel;
use Gemini\Data\Blob;
use Gemini\Data\GenerationConfig;
use Gemini\Data\Schema;
use Gemini\Data\ThinkingConfig;
use Gemini\Enums\DataType;
use Gemini\Enums\FinishReason;
use Gemini\Enums\MimeType;
use Gemini\Enums\ResponseMimeType;

/**
 * Google Gemini through its PHP SDK. Kept as the alternate provider.
 */
final class GeminiLlmClient implements LlmClient
{
    public function generate(string $prompt, LlmOptions $options = new LlmOptions): LlmResponse
    {
        $result = app('gemini')
            ->generativeModel(GeminiModel::flash())
            ->withGenerationConfig(new GenerationConfig(
                maxOutputTokens: $options->maxTokens,
                temperature: $options->temperature,
                responseMimeType: $options->format === LlmFormat::Text
                    ? ResponseMimeType::TEXT_PLAIN
                    : ResponseMimeType::APPLICATION_JSON,
                responseSchema: $options->schema !== null ? $this->schema($options->schema) : null,
                // Same reason as DeepSeek's disabled thinking: the default
                // budget cost ~25s per call with no gain on these tasks.
                thinkingConfig: new ThinkingConfig(includeThoughts: false, thinkingBudget: 0),
            ))
            ->generateContent($options->attachment === null
                ? $prompt
                : [$prompt, new Blob(mimeType: MimeType::TEXT_CSV, data: base64_encode($options->attachment))]);

        return new LlmResponse(
            text: $result->text(),
            truncated: ($result->candidates[0]->finishReason ?? null) === FinishReason::MAX_TOKENS,
        );
    }

    /**
     * Translate a plain JSON Schema array into the SDK's Schema objects.
     *
     * @param  array<string, mixed>  $schema
     */
    private function schema(array $schema): Schema
    {
        return new Schema(
            type: DataType::from(strtoupper((string) $schema['type'])),
            enum: $schema['enum'] ?? null,
            properties: isset($schema['properties'])
                ? array_map(fn (array $property): Schema => $this->schema($property), $schema['properties'])
                : null,
            required: $schema['required'] ?? null,
            items: isset($schema['items']) ? $this->schema($schema['items']) : null,
        );
    }
}
