<?php

namespace App\Services\Ai;

use App\Services\Acquirer\SettlementParser;
use Gemini\Data\GenerationConfig;
use Gemini\Data\Schema;
use Gemini\Data\ThinkingConfig;
use Gemini\Enums\DataType;
use Gemini\Enums\ResponseMimeType;
use Illuminate\Support\Facades\Log;

/**
 * Asks Gemini what each column of an acquirer file MEANS.
 *
 * The deterministic parser already owns delimiter, header row, dates and signs.
 * What it cannot do is semantics: alias matching picks "Tiempo total de entrega"
 * for the amount because the text contains "total", so delivery times would be
 * imported as money. Rappi ships 22 amount-ish columns and Uber 39 columns —
 * choosing between them needs meaning, not substrings.
 *
 * Only the header names plus a few sample rows are sent (150–600 tokens on real
 * files), never the whole file. Every failure degrades to null so the upload
 * keeps working when the AI does not.
 */
class SettlementMappingAssistant
{
    private const MODEL = 'gemini-2.5-flash';

    private const SAMPLE_ROWS = 3;

    /**
     * Suggest a semantic mapping for a file's columns.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string>>  $sampleRows  data rows, as read from the file
     * @return array{columns: array<string, array{index: int, confidence: int, why: string}>, warnings: array<int, string>, glossary: array<int, array{index: int, header: string, meaning: string}>}|null
     */
    public function suggest(array $headers, array $sampleRows, ?string $acquirerName = null): ?array
    {
        if ($headers === []) {
            return null;
        }

        try {
            $result = app('gemini')
                ->generativeModel(self::MODEL)
                ->withGenerationConfig(new GenerationConfig(
                    // Mapping a file is not a creative task; the same file must
                    // always produce the same answer.
                    temperature: 0.0,
                    responseMimeType: ResponseMimeType::APPLICATION_JSON,
                    responseSchema: $this->responseSchema(),
                    // Reading ~40 column names is not a reasoning problem, and the
                    // default thinking budget cost ~25s per call — far too slow for
                    // a screen the user is waiting on.
                    thinkingConfig: new ThinkingConfig(includeThoughts: false, thinkingBudget: 0),
                ))
                ->generateContent($this->prompt($headers, $sampleRows, $acquirerName));

            $decoded = json_decode(trim($result->text()), true);

            if (! is_array($decoded)) {
                Log::warning('Settlement mapping assistant returned unusable JSON');

                return null;
            }

            return $this->normalize($decoded, count($headers));
        } catch (\Throwable $e) {
            // The AI is an assistant, never a dependency: the deterministic
            // mapping stays available and the UI just says so.
            Log::warning('Settlement mapping assistant unavailable', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string>>  $sampleRows
     */
    private function prompt(array $headers, array $sampleRows, ?string $acquirerName): string
    {
        $columnList = '';
        foreach ($headers as $index => $header) {
            $samples = [];
            foreach (array_slice($sampleRows, 0, self::SAMPLE_ROWS) as $row) {
                $value = trim((string) ($row[$index] ?? ''));
                if ($value !== '') {
                    $samples[] = mb_substr($value, 0, 30);
                }
            }
            $columnList .= sprintf(
                "%d | %s | ejemplos: %s\n",
                $index,
                trim((string) $header) !== '' ? trim((string) $header) : '(sin nombre)',
                $samples === [] ? '(vacío)' : implode(', ', $samples),
            );
        }

        $who = $acquirerName !== null && $acquirerName !== ''
            ? "El adquirente es {$acquirerName}."
            : '';

        $fields = implode(', ', SettlementParser::FIELDS);

        return <<<PROMPT
        Eres experto en estados de cuenta de ADQUIRENTES y AGREGADORES de pagos en México
        (bancos como MIFEL o AFIRME, plataformas como Rappi o Uber Eats). Cada renglón del
        archivo es una transacción liquidada al comercio. {$who}

        Abajo están las columnas del archivo con ejemplos reales de sus valores.
        Decide qué columna corresponde a cada campo destino.

        CAMPOS DESTINO (todos opcionales salvo transaction_date y amount):
        {$fields}

        COLUMNAS DEL ARCHIVO (índice | nombre | ejemplos):
        {$columnList}

        REGLAS CRÍTICAS:
        - "amount" es el importe BRUTO de la venta liquidada al comercio. NUNCA elijas la
          comisión, el descuento, el IVA, la propina, una tasa, ni una duración o tiempo.
          Si varias columnas parecen importes, elige la de la venta bruta.
        - "reference" es un folio o identificador de la orden/transacción, NUNCA una fecha.
        - "transaction_date" es la fecha en que ocurrió la transacción, preferible sobre la
          fecha de aplicación, liquidación o depósito.
        - México usa SIEMPRE día/mes, nunca mes/día.
        - Omite del objeto "columns" cualquier campo que no exista en el archivo.
        - "confidence" de 0 a 100.
        - "why": una frase corta en español explicando por qué esa columna.

        Además:
        - "warnings": advertencias en español si algo es ambiguo o sospechoso (por ejemplo,
          varias columnas de importe parecidas). Lista vacía si no hay.
        - "glossary": qué significa CADA columna del archivo, en una frase corta en español.
        PROMPT;
    }

    private function responseSchema(): Schema
    {
        $column = new Schema(
            type: DataType::OBJECT,
            properties: [
                'field' => new Schema(type: DataType::STRING, enum: SettlementParser::FIELDS),
                'index' => new Schema(type: DataType::INTEGER),
                'confidence' => new Schema(type: DataType::INTEGER),
                'why' => new Schema(type: DataType::STRING),
            ],
            required: ['field', 'index', 'confidence', 'why'],
        );

        $glossary = new Schema(
            type: DataType::OBJECT,
            properties: [
                'index' => new Schema(type: DataType::INTEGER),
                'header' => new Schema(type: DataType::STRING),
                'meaning' => new Schema(type: DataType::STRING),
            ],
            required: ['index', 'header', 'meaning'],
        );

        return new Schema(
            type: DataType::OBJECT,
            properties: [
                'columns' => new Schema(type: DataType::ARRAY, items: $column),
                'warnings' => new Schema(type: DataType::ARRAY, items: new Schema(type: DataType::STRING)),
                'glossary' => new Schema(type: DataType::ARRAY, items: $glossary),
            ],
            required: ['columns'],
        );
    }

    /**
     * Shape the model's answer into what the UI expects, dropping anything that
     * does not point at a real column.
     *
     * @param  array<string, mixed>  $decoded
     * @return array{columns: array<string, array{index: int, confidence: int, why: string}>, warnings: array<int, string>, glossary: array<int, array{index: int, header: string, meaning: string}>}
     */
    private function normalize(array $decoded, int $columnCount): array
    {
        $columns = [];
        foreach ($decoded['columns'] ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $field = (string) ($entry['field'] ?? '');
            $index = $entry['index'] ?? null;

            if (! in_array($field, SettlementParser::FIELDS, true)) {
                continue;
            }
            if (! is_numeric($index) || $index < 0 || $index >= $columnCount) {
                continue;
            }

            $columns[$field] = [
                'index' => (int) $index,
                'confidence' => max(0, min(100, (int) ($entry['confidence'] ?? 0))),
                'why' => trim((string) ($entry['why'] ?? '')),
            ];
        }

        $glossary = [];
        foreach ($decoded['glossary'] ?? [] as $entry) {
            if (! is_array($entry) || ! is_numeric($entry['index'] ?? null)) {
                continue;
            }
            $glossary[] = [
                'index' => (int) $entry['index'],
                'header' => trim((string) ($entry['header'] ?? '')),
                'meaning' => trim((string) ($entry['meaning'] ?? '')),
            ];
        }

        $warnings = array_values(array_filter(
            array_map(static fn ($w): string => trim((string) $w), $decoded['warnings'] ?? []),
            static fn (string $w): bool => $w !== '',
        ));

        return ['columns' => $columns, 'warnings' => $warnings, 'glossary' => $glossary];
    }
}
