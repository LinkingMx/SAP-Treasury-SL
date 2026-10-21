<?php

declare(strict_types=1);

namespace App\Services\Ai\Llm;

/**
 * The only door the AI services use to reach a language model.
 *
 * Implementations throw on transport or provider errors; callers translate
 * them with AiErrorTranslator before anything reaches the user.
 */
interface LlmClient
{
    public function generate(string $prompt, LlmOptions $options = new LlmOptions): LlmResponse;
}
