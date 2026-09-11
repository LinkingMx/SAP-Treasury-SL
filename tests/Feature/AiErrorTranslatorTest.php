<?php

use App\Services\Ai\AiErrorTranslator;

/**
 * Google prints the API key inside its own error text, and the upload screens
 * rendered that verbatim — a working credential shown in the browser.
 */
it('never leaks an API key to the user', function (string $raw) {
    $message = AiErrorTranslator::translate(new RuntimeException($raw));

    expect($message)->not->toContain('AIzaSy')
        ->and($message)->not->toMatch('/AIza[0-9A-Za-z_\-]{10,}/');
})->with([
    'suspended' => "Permission denied: Consumer 'api_key:AIzaSyFAKEKEYFORTESTS1234567890abc' has been suspended.",
    'unknown wording' => 'Algo raro pasó con AIzaSyFAKEKEYFORTESTS1234567890abc en el proveedor',
    'bearer' => 'Unauthorized: Bearer ya29.FAKETOKENFORTESTS1234567890',
]);

it('explains a suspended project in Spanish', function (string $raw) {
    expect(AiErrorTranslator::translate(new RuntimeException($raw)))
        ->toContain('no está disponible')
        ->toContain('administrador');
})->with([
    'consumer suspended' => "Permission denied: Consumer 'api_key:AIzaSyFAKE1234567890abcdefg' has been suspended.",
    'denied access' => 'Your project has been denied access. Please contact support.',
    'dunning' => 'Lightning dunning decision is deny for project: projects/345729039354',
]);

it('distinguishes quota, invalid key and connectivity', function (string $raw, string $expected) {
    expect(AiErrorTranslator::translate(new RuntimeException($raw)))->toContain($expected);
})->with([
    ['Quota exceeded (RESOURCE_EXHAUSTED)', 'cuota'],
    ['API key not valid. Please pass a valid API key.', 'no es válida'],
    ['cURL error 28: Operation timed out', 'conectar'],
]);

it('passes through an unrelated message once redacted', function () {
    expect(AiErrorTranslator::translate(new RuntimeException('El archivo está vacío.')))
        ->toBe('El archivo está vacío.');
});
