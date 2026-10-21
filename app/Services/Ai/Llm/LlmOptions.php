<?php

declare(strict_types=1);

namespace App\Services\Ai\Llm;

/**
 * How a single request should be answered, independent of the provider.
 */
final readonly class LlmOptions
{
    /**
     * @param  string|null  $attachment  raw file text (CSV) the prompt refers to
     * @param  array<string, mixed>|null  $schema  JSON Schema of the expected object (lowercase types)
     */
    public function __construct(
        public LlmFormat $format = LlmFormat::Text,
        public ?string $attachment = null,
        public ?array $schema = null,
        public ?int $maxTokens = null,
        public ?float $temperature = null,
    ) {}
}
