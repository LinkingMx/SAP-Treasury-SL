<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * The Gemini model every AI service in this app talks to.
 *
 * This exists because the model name used to be hardcoded in four call sites.
 * When Google retired `gemini-2.5-flash` for new projects, swapping the API key
 * was not enough: every AI feature answered 404 until the four strings were
 * edited and redeployed. Keeping the name in config means the next retirement
 * is an environment change, not a release.
 */
final class GeminiModel
{
    /**
     * The fast, cheap model used for column mapping, layout analysis and
     * transaction classification.
     */
    public static function flash(): string
    {
        return (string) config('gemini.model');
    }
}
