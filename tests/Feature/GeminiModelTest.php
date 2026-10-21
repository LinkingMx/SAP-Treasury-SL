<?php

use App\Services\Ai\GeminiModel;
use App\Services\Ai\SettlementMappingAssistant;
use Gemini\Laravel\Facades\Gemini;
use Gemini\Resources\GenerativeModel;
use Gemini\Responses\GenerativeModel\GenerateContentResponse;

/**
 * Google retires models for new projects while existing ones keep working, so a
 * perfectly valid API key can answer 404 ("gemini-2.5-flash is no longer
 * available to new users"). The name therefore lives in config, not in the
 * services, so the successor is an environment change rather than a release.
 */
it('never falls back to a model Google retired for new projects', function () {
    expect(GeminiModel::flash())->not->toBe('gemini-2.5-flash')
        ->and(config('gemini.model'))->toBe('gemini-3.8-flash');
});

it('takes the model from configuration so it can be changed without a release', function () {
    config(['gemini.model' => 'gemini-4-flash']);

    expect(GeminiModel::flash())->toBe('gemini-4-flash');
});

it('asks the configured model, not a hardcoded one', function () {
    config(['gemini.model' => 'gemini-4-flash']);

    Gemini::fake([
        GenerateContentResponse::fake([
            'candidates' => [[
                'content' => ['parts' => [['text' => json_encode([
                    'columns' => [['field' => 'amount', 'index' => 1, 'confidence' => 90, 'why' => 'Importe.']],
                ])]]],
                'finishReason' => 'STOP',
            ]],
        ]),
    ]);

    app(SettlementMappingAssistant::class)->suggest(['Fecha', 'Monto'], [['01/01/2026', '10']]);

    Gemini::assertSent(GenerativeModel::class, 'gemini-4-flash');
});
