<?php

namespace App\Services\Ai;

use App\Models\BankLayoutTemplate;
use Carbon\Carbon;
use Gemini\Client as GeminiClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class BankLayoutAnalyzer
{
    /**
     * Analyze an uploaded file and return parse configuration.
     *
     * @return array{parse_config: array, bank_name_guess: string|null, fingerprint: string, is_cached: bool}
     */
    public function analyze(UploadedFile $file): array
    {
        $spreadsheet = IOFactory::load($file->getPathname());
        $sheet = $spreadsheet->getActiveSheet();
        $data = $sheet->toArray();

        if (count($data) < 2) {
            throw new \InvalidArgumentException('El archivo debe contener al menos una fila de encabezados y una de datos.');
        }

        $headers = $data[0];
        $sampleRows = array_slice($data, 1, 5);

        $fingerprint = $this->generateFingerprint($headers);

        // Check if we have a cached template
        $existingTemplate = BankLayoutTemplate::findByFingerprint($fingerprint);
        if ($existingTemplate) {
            Log::info('Using cached bank layout template', ['fingerprint' => $fingerprint]);

            return [
                'parse_config' => $existingTemplate->parse_config,
                'bank_name_guess' => $existingTemplate->bank_name_guess,
                'fingerprint' => $fingerprint,
                'is_cached' => true,
            ];
        }

        // Detect layout using AI
        $parseConfig = $this->detectLayoutWithAi($headers, $sampleRows);

        // Cache the template
        BankLayoutTemplate::create([
            'fingerprint' => $fingerprint,
            'bank_name_guess' => $parseConfig['bank_name_guess'] ?? null,
            'parse_config' => $parseConfig,
        ]);

        Log::info('New bank layout template detected and cached', [
            'fingerprint' => $fingerprint,
            'bank_name_guess' => $parseConfig['bank_name_guess'] ?? null,
        ]);

        return [
            'parse_config' => $parseConfig,
            'bank_name_guess' => $parseConfig['bank_name_guess'] ?? null,
            'fingerprint' => $fingerprint,
            'is_cached' => false,
        ];
    }

    /**
     * Parse transactions from a file using a given configuration.
     *
     * @return array<int, array{sequence: int, due_date: string, memo: string, debit_amount: float|null, credit_amount: float|null}>
     */
    public function parseTransactions(UploadedFile $file, array $parseConfig): array
    {
        $spreadsheet = IOFactory::load($file->getPathname());
        $sheet = $spreadsheet->getActiveSheet();
        $data = $sheet->toArray();

        $dataStartRow = $parseConfig['data_start_row'] ?? 1;
        $columns = $parseConfig['columns'];

        $transactions = [];
        $sequence = 1;

        for ($i = $dataStartRow; $i < count($data); $i++) {
            $row = $data[$i];

            // Skip empty rows
            if ($this->isEmptyRow($row)) {
                continue;
            }

            $dateValue = $row[$columns['date']['index']] ?? null;
            $description = trim($row[$columns['description']['index']] ?? '');

            // Parse date
            $dueDate = $this->parseDate($dateValue, $columns['date']['format']);
            if (! $dueDate) {
                continue;
            }

            // Parse amounts
            $debitAmount = null;
            $creditAmount = null;

            if (isset($columns['signed_amount']) && $columns['signed_amount'] !== null) {
                $amount = $this->parseAmount($row[$columns['signed_amount']['index']] ?? null);
                if ($amount !== null) {
                    if ($amount < 0) {
                        $debitAmount = abs($amount);
                    } else {
                        $creditAmount = $amount;
                    }
                }
            } else {
                if (isset($columns['debit'])) {
                    $debitAmount = $this->parseAmount($row[$columns['debit']['index']] ?? null);
                }
                if (isset($columns['credit'])) {
                    $creditAmount = $this->parseAmount($row[$columns['credit']['index']] ?? null);
                }
            }

            // Skip rows without any amount
            if ($debitAmount === null && $creditAmount === null) {
                continue;
            }

            $transactions[] = [
                'sequence' => $sequence++,
                'due_date' => $dueDate->format('Y-m-d'),
                'memo' => $description,
                'debit_amount' => $debitAmount,
                'credit_amount' => $creditAmount,
            ];
        }

        return $transactions;
    }

    /**
     * Generate a fingerprint from headers.
     */
    public function generateFingerprint(array $headers): string
    {
        $normalized = array_map(function ($header) {
            return strtolower(trim((string) $header));
        }, $headers);

        return md5(implode('|', $normalized));
    }

    /**
     * Detect layout configuration using AI.
     */
    protected function detectLayoutWithAi(array $headers, array $sampleRows): array
    {
        $headersString = implode(', ', array_map(fn ($h) => '"'.($h ?? '').'"', $headers));
        $sampleRowsJson = json_encode($sampleRows, JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
Eres un Ingeniero de Datos experto. Tu tarea es generar una configuración JSON para parsear un archivo CSV/Excel bancario desconocido.

INPUT DATA:
Headers: {$headersString}
Sample Rows: {$sampleRowsJson}

REGLAS DE DETECCIÓN CRÍTICAS:
1. FECHAS (Date):
   - Si los datos son números flotantes (ej: 45992.0), el formato es 'excel_serial'.
   - Si los datos tienen comillas simples (ej: '31122025' o '01122025'), el formato es 'quoted_dmY'.
   - Si es estándar día/mes/año (31/12/2025 o 1/12/2025), es 'standard_dmy'.
   - Si es formato americano mes/día/año (12/31/2025), es 'standard_mdy'.
2. MONTOS (Amounts):
   - Detecta si hay columnas separadas para 'Cargo' (Debit) y 'Abono' (Credit).
   - O si es una sola columna 'Monto' con signos (+/-).
   - Los valores "  -   " o vacíos significan null (sin monto).
   - Los montos pueden tener comas como separador de miles y espacios alrededor.
3. DESCRIPCIÓN:
   - Identifica la columna con el texto más descriptivo del movimiento bancario.
   - Puede llamarse 'Descripcion', 'Concepto', 'Detalle', etc.

OUTPUT JSON FORMAT (Strict - solo el JSON, sin texto adicional):
{
    "bank_name_guess": "String con el nombre probable del banco",
    "header_row_index": 0,
    "data_start_row": 1,
    "columns": {
        "date": { "index": 0, "format": "excel_serial|quoted_dmY|standard_dmy|standard_mdy" },
        "description": { "index": 3 },
        "debit": { "index": 4, "is_signed": false },
        "credit": { "index": 5, "is_signed": false },
        "signed_amount": null
    }
}

Si hay una sola columna de monto con signos, usa signed_amount en lugar de debit/credit:
{
    "columns": {
        "date": {...},
        "description": {...},
        "debit": null,
        "credit": null,
        "signed_amount": { "index": 4 }
    }
}

Responde SOLO con el JSON, sin explicaciones adicionales.
PROMPT;

        try {
            /** @var GeminiClient $gemini */
            $gemini = app('gemini');
            $result = $gemini->generativeModel('gemini-2.0-flash')->generateContent($prompt);
            $responseText = $result->text();

            // Extract JSON from response
            $responseText = trim($responseText);
            if (str_starts_with($responseText, '```json')) {
                $responseText = substr($responseText, 7);
            }
            if (str_starts_with($responseText, '```')) {
                $responseText = substr($responseText, 3);
            }
            if (str_ends_with($responseText, '```')) {
                $responseText = substr($responseText, 0, -3);
            }
            $responseText = trim($responseText);

            $parseConfig = json_decode($responseText, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('Failed to parse AI response as JSON', [
                    'response' => $responseText,
                    'error' => json_last_error_msg(),
                ]);
                throw new \RuntimeException('La IA no pudo analizar el formato del archivo.');
            }

            return $parseConfig;

        } catch (\Exception $e) {
            Log::error('AI layout detection failed', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Error al detectar el formato del archivo: '.$e->getMessage());
        }
    }

    /**
     * Parse a date value based on format.
     */
    public function parseDate(mixed $value, string $format): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            switch ($format) {
                case 'excel_serial':
                    if (is_numeric($value)) {
                        return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value));
                    }

                    return null;

                case 'quoted_dmY':
                    $cleaned = trim((string) $value, "' ");
                    if (strlen($cleaned) === 8 && is_numeric($cleaned)) {
                        return Carbon::createFromFormat('dmY', $cleaned);
                    }

                    return null;

                case 'standard_dmy':
                    return Carbon::createFromFormat('d/m/Y', (string) $value);

                case 'standard_mdy':
                    return Carbon::createFromFormat('m/d/Y', (string) $value);

                default:
                    return Carbon::parse((string) $value);
            }
        } catch (\Exception $e) {
            Log::warning('Date parsing failed', [
                'value' => $value,
                'format' => $format,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Parse an amount value.
     */
    public function parseAmount(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $stringValue = trim((string) $value);

        // Check for empty/dash values
        if ($stringValue === '-' || preg_match('/^\s*-\s*$/', $stringValue)) {
            return null;
        }

        // Remove thousands separators and normalize decimal
        $cleaned = str_replace([',', ' '], ['', ''], $stringValue);
        $cleaned = trim($cleaned);

        if ($cleaned === '' || $cleaned === '-') {
            return null;
        }

        if (is_numeric($cleaned)) {
            return (float) $cleaned;
        }

        return null;
    }

    /**
     * Check if a row is empty.
     */
    protected function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
