<?php

declare(strict_types=1);

namespace App\Services\Ai\Llm;

final readonly class LlmResponse
{
    /**
     * @param  bool  $truncated  the model stopped because it hit the output limit
     */
    public function __construct(
        public string $text,
        public bool $truncated = false,
    ) {}

    /**
     * Decode the answer as JSON, tolerating a Markdown code fence around it.
     *
     * Models wrap JSON in ```json fences even when told not to; every caller
     * used to strip them by hand.
     */
    public function json(): mixed
    {
        $text = trim($this->text);
        $text = (string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text);

        return json_decode(trim($text), true);
    }
}
