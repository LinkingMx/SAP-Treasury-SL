<?php

namespace App\Services\Ai;

use Carbon\Carbon;
use Gemini\Data\Blob;
use Gemini\Data\GenerationConfig;
use Gemini\Enums\MimeType;
use Gemini\Enums\ResponseMimeType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Detects the column layout of an acquirer/aggregator settlement file (MIFEL,
 * AFIRME, Rappi, Uber Eats, ...) using Gemini on a small sample, then extracts
 * every row deterministically. Sibling of BankLayoutAnalyzer — same mechanism,
 * different target schema (settlement rows, not bank-statement transactions).
 */
class SettlementLayoutAnalyzer
{
    /**
     * Detect the settlement file layout from a sample of the first rows.
     *
     * @return array{parse_config: array, acquirer_guess: string|null, fingerprint: string, is_cached: bool}
     */
    public function analyze(UploadedFile $file): array
    {
        $rawContent = $this->readFileAsText($file);

        if (trim($rawContent) === '') {
            throw new \InvalidArgumentException('El archivo está vacío.');
        }

        $sample = implode("\n", array_slice(explode("\n", $rawContent), 0, 30));

        $prompt = <<<'PROMPT'
Analiza este estado de cuenta de un ADQUIRENTE o AGREGADOR de pagos (banco como MIFEL/AFIRME o plataforma como Rappi/Uber Eats) y devuelve un JSON con la estructura EXACTA descrita abajo. Cada renglón representa una transacción liquidada al comercio.

CAMPOS REQUERIDOS:
1. "acquirer_guess": nombre del adquirente/agregador detectado (string)
2. "header_lines_count": número de líneas ANTES del primer renglón real de transacción (títulos, nombres de columna, líneas vacías)
3. "column_description": descripción textual del formato como respaldo (string)
4. "delimiter": separador entre columnas. Usa "\t" para tabulador, "," para coma, ";" para punto y coma, "|" para pipe
5. "columns": objeto con los índices de columna (BASE 0) para cada campo que EXISTA en el archivo. Omite los que no existan.

CAMPOS POSIBLES dentro de "columns" (todos opcionales salvo date y amount):
- "transaction_date": { "index": int, "format": "DD/MM/YYYY" }   (OBLIGATORIO)
- "transaction_time": { "index": int }                            (hora de la transacción, si existe)
- "amount": { "index": int }                                      (OBLIGATORIO; monto liquidado, una sola columna)
- "authorization": { "index": int }                              (número de autorización)
- "card_type": { "index": int }                                  (CREDITO/DEBITO)
- "card_brand": { "index": int }                                 (VISA/MASTER/AMEX)
- "reference": { "index": int }
- "terminal": { "index": int }
- "operation_type": { "index": int }                             (VENTA/DEVOLUCION)
- "status": { "index": int }

EJEMPLO:
{
  "acquirer_guess": "MIFEL",
  "header_lines_count": 2,
  "column_description": "Col 0: Fecha DD/MM/YYYY, Col 1: Hora, Col 2: Autorización, Col 3: Tarjeta, Col 4: Monto",
  "delimiter": "\t",
  "columns": {
    "transaction_date": { "index": 0, "format": "DD/MM/YYYY" },
    "transaction_time": { "index": 1 },
    "authorization": { "index": 2 },
    "card_type": { "index": 3 },
    "amount": { "index": 4 }
  }
}

REGLAS PARA transaction_date.format:
- "DD/MM/YY" para fechas como 17/02/26
- "DD/MM/YYYY" para fechas como 17/02/2026
- "YYYY-MM-DD" para fechas como 2026-02-17
- Este sistema es para México: el formato es SIEMPRE DD/MM (día/mes), NUNCA MM/DD.

IMPORTANTE:
- Los índices son BASE 0 (la primera columna es 0).
- "amount" es UNA sola columna de monto liquidado (positivo). No la columna de propina ni de comisión.
- header_lines_count debe incluir TODAS las líneas antes de los datos reales.

Responde SOLO el JSON, sin explicaciones.
PROMPT;

        /** @var \Gemini\Client $gemini */
        $gemini = app('gemini');

        $result = $gemini->generativeModel('gemini-2.5-flash')
            ->withGenerationConfig(new GenerationConfig(
                responseMimeType: ResponseMimeType::APPLICATION_JSON,
            ))
            ->generateContent([
                $prompt,
                new Blob(
                    mimeType: MimeType::TEXT_CSV,
                    data: base64_encode($sample),
                ),
            ]);

        $parsed = json_decode(trim($result->text()), true) ?? [];

        Log::info('Settlement layout analysis complete', [
            'acquirer_guess' => $parsed['acquirer_guess'] ?? 'Desconocido',
            'header_lines_count' => $parsed['header_lines_count'] ?? 'unknown',
            'columns' => array_keys($parsed['columns'] ?? []),
            'delimiter' => $parsed['delimiter'] ?? 'unknown',
        ]);

        return [
            'parse_config' => $parsed,
            'acquirer_guess' => $parsed['acquirer_guess'] ?? 'Desconocido',
            'fingerprint' => md5($sample),
            'is_cached' => false,
        ];
    }

    /**
     * Extract every settlement row deterministically using the detected column map.
     *
     * @return array<int, array{
     *   transaction_date: string,
     *   transaction_time: string|null,
     *   amount: float,
     *   card_type: string|null,
     *   card_brand: string|null,
     *   authorization: string|null,
     *   reference: string|null,
     *   terminal: string|null,
     *   operation_type: string|null,
     *   status: string|null,
     *   raw: array<int, string>
     * }>
     */
    public function parseRows(UploadedFile $file, array $parseConfig): array
    {
        $columns = $parseConfig['columns'] ?? [];

        if (empty($columns) || ! is_array($columns)) {
            throw new \RuntimeException('El análisis de columnas no detectó una estructura válida en el archivo.');
        }

        $rawContent = $this->readFileAsText($file);
        $lines = explode("\n", $rawContent);

        $headerLinesCount = (int) ($parseConfig['header_lines_count'] ?? 0);
        $delimiter = $parseConfig['delimiter'] ?? "\t";
        if ($delimiter === '\t') {
            $delimiter = "\t";
        }

        $dateIndex = $columns['transaction_date']['index'] ?? null;
        $dateFormat = $columns['transaction_date']['format'] ?? 'DD/MM/YYYY';
        $amountIndex = $columns['amount']['index'] ?? null;

        if ($dateIndex === null || $amountIndex === null) {
            throw new \RuntimeException('El análisis de columnas no detectó fecha y monto, indispensables para conciliar.');
        }

        $rows = [];

        foreach (array_slice($lines, $headerLinesCount) as $line) {
            $line = rtrim($line, "\r");
            if (trim($line) === '') {
                continue;
            }

            $fields = $this->splitLine($line, $delimiter);

            if (! isset($fields[$dateIndex])) {
                continue;
            }
            $date = $this->parseDate(trim($fields[$dateIndex]), $dateFormat);
            if ($date === null) {
                continue;
            }

            if (! isset($fields[$amountIndex])) {
                continue;
            }
            $amount = $this->cleanAmount(trim($fields[$amountIndex]));
            if ($amount === null || $amount == 0.0) {
                continue;
            }

            $rows[] = [
                'transaction_date' => $date,
                'transaction_time' => $this->valueAt($fields, $columns, 'transaction_time'),
                'amount' => abs($amount),
                'card_type' => $this->valueAt($fields, $columns, 'card_type'),
                'card_brand' => $this->valueAt($fields, $columns, 'card_brand'),
                'authorization' => $this->valueAt($fields, $columns, 'authorization'),
                'reference' => $this->valueAt($fields, $columns, 'reference'),
                'terminal' => $this->valueAt($fields, $columns, 'terminal'),
                'operation_type' => $this->valueAt($fields, $columns, 'operation_type'),
                'status' => $this->valueAt($fields, $columns, 'status'),
                'raw' => $fields,
            ];
        }

        if (empty($rows)) {
            throw new \RuntimeException('No se pudieron extraer renglones del archivo con el análisis de columnas.');
        }

        Log::info('Settlement deterministic extraction complete', ['count' => count($rows)]);

        return $rows;
    }

    /**
     * Pull a trimmed string value for an optional column, or null if absent/empty.
     *
     * @param  array<int, string>  $fields
     * @param  array<string, mixed>  $columns
     */
    protected function valueAt(array $fields, array $columns, string $key): ?string
    {
        $index = $columns[$key]['index'] ?? null;
        if ($index === null || ! isset($fields[$index])) {
            return null;
        }

        $value = trim((string) $fields[$index]);

        return $value === '' ? null : $value;
    }

    /**
     * Split a delimited line into fields.
     *
     * @return array<int, string>
     */
    protected function splitLine(string $line, string $delimiter): array
    {
        if ($delimiter === ',' || $delimiter === ';') {
            return str_getcsv($line, $delimiter);
        }

        return explode($delimiter, $line);
    }

    /**
     * Clean a raw amount string into a float. "$1,234.56" → 1234.56, "" → null.
     */
    protected function cleanAmount(string $raw): ?float
    {
        $cleaned = preg_replace('/[\s$€£¥,]/', '', $raw);

        if (preg_match('/^\((.+)\)$/', $cleaned, $m)) {
            $cleaned = '-'.$m[1];
        }

        if ($cleaned === '' || $cleaned === '-' || ! is_numeric($cleaned)) {
            return null;
        }

        return (float) $cleaned;
    }

    /**
     * Parse a date string using the format hint. Returns YYYY-MM-DD or null.
     */
    protected function parseDate(string $raw, string $format): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        try {
            if (is_numeric($raw) && (float) $raw >= 36526 && (float) $raw <= 73415) {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $raw)->format('Y-m-d');
            }

            $sep = str_contains($raw, '/') ? '/' : '-';
            $parts = explode($sep, explode(' ', $raw)[0]);

            if (count($parts) !== 3) {
                return Carbon::parse($raw)->format('Y-m-d');
            }

            if ($format === 'YYYY-MM-DD') {
                [$year, $month, $day] = [(int) $parts[0], (int) $parts[1], (int) $parts[2]];
            } else {
                [$day, $month, $year] = [(int) $parts[0], (int) $parts[1], (int) $parts[2]];
                $year = $year < 100 ? $year + 2000 : $year;
            }

            if (! checkdate($month, $day, $year)) {
                return null;
            }

            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Read any file as plain text. Excel → tab-separated, CSV → as-is.
     */
    protected function readFileAsText(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (in_array($extension, ['xlsx', 'xls'])) {
            $spreadsheet = IOFactory::load($file->getPathname());
            $sheet = $spreadsheet->getActiveSheet();
            $data = $sheet->toArray(null, true, false);

            $lines = [];
            foreach ($data as $rowIndex => $row) {
                $cellValues = [];
                foreach ($row as $colIndex => $value) {
                    if (is_numeric($value) && (float) $value >= 36526 && (float) $value <= 73415) {
                        $coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex + 1).($rowIndex + 1);
                        $cell = $sheet->getCell($coord);
                        if (\PhpOffice\PhpSpreadsheet\Shared\Date::isDateTime($cell)) {
                            try {
                                $cellValues[] = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value)->format('d/m/Y');

                                continue;
                            } catch (\Exception $e) {
                                // Fall through to raw value.
                            }
                        }
                    }
                    $cellValues[] = (string) ($value ?? '');
                }
                $lines[] = implode("\t", $cellValues);
            }

            return implode("\n", $lines);
        }

        return file_get_contents($file->getPathname());
    }
}
