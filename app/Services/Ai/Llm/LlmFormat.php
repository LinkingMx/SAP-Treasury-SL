<?php

declare(strict_types=1);

namespace App\Services\Ai\Llm;

/**
 * The shape the caller expects back.
 *
 * Object and array are separate because providers disagree on them: DeepSeek's
 * JSON mode only accepts an object at the root, so array answers must be asked
 * for in the prompt instead of through the API's JSON mode.
 */
enum LlmFormat: string
{
    case JsonObject = 'json_object';
    case JsonArray = 'json_array';
    case Text = 'text';
}
