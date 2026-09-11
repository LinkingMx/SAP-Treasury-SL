<?php

namespace App\Services\Ai;

use Throwable;

/**
 * Turns a provider failure into something a treasury user can act on — without
 * ever echoing what the provider said.
 *
 * Google embeds the API key in its own error text ("Consumer 'api_key:AIza...'
 * has been suspended"), and the upload screens rendered that verbatim, so a
 * working credential was printed in the browser for anyone who hit the error.
 */
final class AiErrorTranslator
{
    private const UNAVAILABLE = 'El análisis con IA no está disponible en este momento (problema de acceso o facturación en la cuenta de Google). Contacta al administrador.';

    /**
     * A safe, Spanish message for the end user.
     */
    public static function translate(Throwable $e): string
    {
        $raw = mb_strtolower($e->getMessage());

        return match (true) {
            str_contains($raw, 'suspended'),
            str_contains($raw, 'denied access'),
            str_contains($raw, 'permission denied'),
            str_contains($raw, 'permission_denied'),
            str_contains($raw, 'dunning') => self::UNAVAILABLE,

            str_contains($raw, 'api key not valid'),
            str_contains($raw, 'api_key_invalid'),
            str_contains($raw, 'invalid api key') => 'La clave de acceso al servicio de IA no es válida. Contacta al administrador.',

            str_contains($raw, 'quota'),
            str_contains($raw, 'resource_exhausted'),
            str_contains($raw, 'rate limit'),
            str_contains($raw, 'too many requests') => 'Se agotó la cuota del servicio de IA. Intenta de nuevo más tarde.',

            str_contains($raw, 'timed out'),
            str_contains($raw, 'timeout'),
            str_contains($raw, 'could not resolve'),
            str_contains($raw, 'connection') => 'No se pudo conectar con el servicio de análisis con IA. Verifica la conexión e intenta de nuevo.',

            // Anything unrecognised is still passed through redacted: an unknown
            // provider message must never be trusted to be credential-free.
            default => self::redact($e->getMessage()),
        };
    }

    /**
     * Strip anything that looks like a credential from a string.
     */
    public static function redact(string $message): string
    {
        $patterns = [
            '/AIza[0-9A-Za-z_\-]{10,}/',        // Google API keys
            '/\bsk-[0-9A-Za-z_\-]{10,}/',       // OpenAI-style keys
            '/\bBearer\s+[0-9A-Za-z._\-]{10,}/i',
            '/api[_-]?key["\':=\s]+[0-9A-Za-z._\-]{10,}/i',
        ];

        return (string) preg_replace($patterns, '[credencial oculta]', $message);
    }
}
