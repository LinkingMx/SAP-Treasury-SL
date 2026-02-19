<?php

namespace App\Services\Ai;

use Carbon\Carbon;
use Gemini\Data\Blob;
use Gemini\Data\GenerationConfig;
use Gemini\Enums\FinishReason;
use Gemini\Enums\MimeType;
use Gemini\Enums\ResponseMimeType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;

class BankLayoutAnalyzer
{
    /**
     * Step 1: Analyze layout — AI detects bank name, header structure, and column format.
     *
     * @return array{parse_config: array, bank_name_guess: string|null, fingerprint: string, is_cached: bool}
     */
    public function analyze(UploadedFile $file): array
    {
        $rawContent = $this->readFileAsText($file);

        if (trim($rawContent) === '') {
            throw new \InvalidArgumentException('El archivo está vacío.');
        }

        $sample = implode("\n", array_slice(explode("\n", $rawContent), 0, 30));

        $prompt = <<<'PROMPT'
Analiza este extracto bancario y devuelve un JSON con la estructura EXACTA descrita abajo.

CAMPOS REQUERIDOS:
1. "bank_name_guess": nombre del banco detectado (string)
2. "header_lines_count": número de líneas ANTES de la primera transacción real (encabezados del banco, títulos de columna, líneas vacías, etc.)
3. "column_description": descripción textual del formato como respaldo (string)
4. "delimiter": el delimitador entre columnas. Usa "\t" para tabulador, "," para coma, ";" para punto y coma, "|" para pipe
5. "amount_style": "separate" si hay columnas separadas para débito y crédito, "single_signed" si hay UNA sola columna con montos positivos/negativos
6. "columns": objeto con los índices de columna (base 0) para cada campo

PARA amount_style "separate" (columnas separadas de cargo y abono):
{
  "bank_name_guess": "Santander",
  "header_lines_count": 5,
  "column_description": "Col 0: Fecha DD/MM/YY, Col 1: Descripción, Col 2: Cargo, Col 3: Abono, Col 4: Saldo",
  "delimiter": "\t",
  "amount_style": "separate",
  "columns": {
    "date": { "index": 0, "format": "DD/MM/YY" },
    "memo": { "index": 1 },
    "debit": { "index": 2 },
    "credit": { "index": 3 }
  }
}

PARA amount_style "single_signed" (una sola columna de monto):
{
  "columns": {
    "date": { "index": 0, "format": "DD/MM/YYYY" },
    "memo": { "index": 1 },
    "amount": { "index": 2 }
  },
  "amount_style": "single_signed"
}

REGLAS PARA date.format:
- "DD/MM/YY" para fechas como 17/02/26
- "DD/MM/YYYY" para fechas como 17/02/2026
- "YYYY-MM-DD" para fechas como 2026-02-17
- "DD-MM-YY" o "DD-MM-YYYY" para guiones

IMPORTANTE:
- Los índices son BASE 0 (la primera columna es 0)
- NO incluyas la columna de saldo/balance en columns (no se usa)
- header_lines_count debe incluir TODAS las líneas antes de los datos reales (títulos, subtítulos, línea de nombres de columna, líneas vacías)
- Cuenta con cuidado: si la primera transacción real está en la línea 6, header_lines_count = 5

Responde SOLO el JSON, sin explicaciones.
PROMPT;

        /** @var \Gemini\Client $gemini */
        $gemini = app('gemini');

        $result = $gemini->generativeModel('gemini-2.0-flash')
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

        Log::info('AI layout analysis complete', [
            'bank_name_guess' => $parsed['bank_name_guess'] ?? 'Desconocido',
            'header_lines_count' => $parsed['header_lines_count'] ?? 'unknown',
            'amount_style' => $parsed['amount_style'] ?? 'unknown',
            'columns' => $parsed['columns'] ?? 'missing',
            'delimiter' => $parsed['delimiter'] ?? 'unknown',
            'column_description' => $parsed['column_description'] ?? 'unknown',
        ]);

        return [
            'parse_config' => $parsed,
            'bank_name_guess' => $parsed['bank_name_guess'] ?? 'Desconocido',
            'fingerprint' => md5($sample),
            'is_cached' => false,
        ];
    }

    /**
     * Step 2: Extract transactions — uses deterministic PHP parsing when structured
     * columns are available, falls back to AI chunk extraction otherwise.
     *
     * @param  callable|null  $onProgress  fn(array $event): void — emits progress events
     * @return array<int, array{sequence: int, due_date: string, memo: string, debit_amount: float|null, credit_amount: float|null}>
     */
    public function parseTransactions(UploadedFile $file, array $parseConfig, ?callable $onProgress = null): array
    {
        // Route to deterministic parsing if structured columns are available
        if (! empty($parseConfig['columns']) && is_array($parseConfig['columns'])) {
            Log::info('Using deterministic PHP parsing (structured columns available)');

            return $this->parseTransactionsDeterministic($file, $parseConfig, $onProgress);
        }

        Log::info('Falling back to AI chunk extraction (no structured columns)');

        return $this->parseTransactionsWithAi($file, $parseConfig, $onProgress);
    }

    /**
     * Deterministic PHP-based transaction extraction using column indices from AI layout analysis.
     *
     * @return array<int, array{sequence: int, due_date: string, memo: string, debit_amount: float|null, credit_amount: float|null}>
     */
    protected function parseTransactionsDeterministic(UploadedFile $file, array $parseConfig, ?callable $onProgress = null): array
    {
        $rawContent = $this->readFileAsText($file);
        $lines = explode("\n", $rawContent);
        $totalLines = count($lines);

        $headerLinesCount = (int) ($parseConfig['header_lines_count'] ?? 0);
        $columns = $parseConfig['columns'];
        $amountStyle = $parseConfig['amount_style'] ?? 'separate';
        $delimiter = $parseConfig['delimiter'] ?? "\t";

        // Unescape delimiter (AI may return literal \t)
        if ($delimiter === '\t') {
            $delimiter = "\t";
        }

        $dataLines = array_slice($lines, $headerLinesCount);
        $dataLineCount = count($dataLines);

        $emit = $onProgress ?? fn (array $e) => null;
        $emit([
            'event' => 'extraction_start',
            'total_lines' => $totalLines,
            'data_lines' => $dataLineCount,
            'header_lines' => $headerLinesCount,
            'total_chunks' => 1,
            'column_description' => $parseConfig['column_description'] ?? '',
        ]);

        $emit(['event' => 'chunk_progress', 'current' => 1, 'total' => 1]);

        $dateIndex = $columns['date']['index'] ?? null;
        $dateFormat = $columns['date']['format'] ?? 'DD/MM/YY';
        $memoIndex = $columns['memo']['index'] ?? null;

        $transactions = [];

        foreach ($dataLines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $fields = $this->splitLine($line, $delimiter);

            // Parse date — skip row if no valid date
            if ($dateIndex === null || ! isset($fields[$dateIndex])) {
                continue;
            }
            $dateValue = $this->parseDate(trim($fields[$dateIndex]), $dateFormat);
            if ($dateValue === null) {
                continue;
            }

            // Parse memo
            $memo = ($memoIndex !== null && isset($fields[$memoIndex]))
                ? trim($fields[$memoIndex])
                : '';

            // Parse amounts
            $debitAmount = null;
            $creditAmount = null;

            if ($amountStyle === 'single_signed') {
                $amountIndex = $columns['amount']['index'] ?? null;
                if ($amountIndex !== null && isset($fields[$amountIndex])) {
                    $amount = $this->cleanAmount(trim($fields[$amountIndex]));
                    if ($amount !== null) {
                        if ($amount < 0) {
                            $debitAmount = abs($amount);
                        } else {
                            $creditAmount = $amount;
                        }
                    }
                }
            } else {
                // "separate" — distinct debit and credit columns
                $debitIndex = $columns['debit']['index'] ?? null;
                $creditIndex = $columns['credit']['index'] ?? null;

                if ($debitIndex !== null && isset($fields[$debitIndex])) {
                    $debitAmount = $this->cleanAmount(trim($fields[$debitIndex]));
                }
                if ($creditIndex !== null && isset($fields[$creditIndex])) {
                    $creditAmount = $this->cleanAmount(trim($fields[$creditIndex]));
                }
            }

            // Skip rows without any amount (subtotals, footers, etc.)
            if ($debitAmount === null && $creditAmount === null) {
                continue;
            }

            // Treat zero amounts as null for cleaner data
            if ($debitAmount !== null && $debitAmount == 0.0) {
                $debitAmount = null;
            }
            if ($creditAmount !== null && $creditAmount == 0.0) {
                $creditAmount = null;
            }

            // Skip if both ended up null after zero cleanup
            if ($debitAmount === null && $creditAmount === null) {
                continue;
            }

            $transactions[] = [
                'sequence' => count($transactions) + 1,
                'due_date' => $dateValue,
                'memo' => $memo,
                'debit_amount' => $debitAmount,
                'credit_amount' => $creditAmount,
            ];
        }

        $emit(['event' => 'chunk_done', 'current' => 1, 'total' => 1, 'extracted' => count($transactions)]);

        Log::info('Deterministic PHP extraction complete', [
            'count' => count($transactions),
            'amount_style' => $amountStyle,
            'delimiter' => $delimiter === "\t" ? 'TAB' : $delimiter,
        ]);

        if (empty($transactions)) {
            throw new \RuntimeException('No se pudieron extraer transacciones del archivo con el análisis de columnas.');
        }

        return $transactions;
    }

    /**
     * AI-based transaction extraction (original chunk approach — used as fallback).
     *
     * @return array<int, array{sequence: int, due_date: string, memo: string, debit_amount: float|null, credit_amount: float|null}>
     */
    protected function parseTransactionsWithAi(UploadedFile $file, array $parseConfig, ?callable $onProgress = null): array
    {
        $rawContent = $this->readFileAsText($file);
        $lines = explode("\n", $rawContent);
        $totalLines = count($lines);

        $headerLinesCount = (int) ($parseConfig['header_lines_count'] ?? 0);
        $columnDescription = $parseConfig['column_description'] ?? '';

        $chunkSize = 80;

        // Keep actual header lines to prepend to each chunk as context
        $headerContent = implode("\n", array_slice($lines, 0, $headerLinesCount));
        $dataLines = array_slice($lines, $headerLinesCount);
        $dataLineCount = count($dataLines);
        $totalChunks = $dataLineCount <= $chunkSize + 10 ? 1 : (int) ceil($dataLineCount / $chunkSize);

        $emit = $onProgress ?? fn (array $e) => null;
        $emit([
            'event' => 'extraction_start',
            'total_lines' => $totalLines,
            'data_lines' => $dataLineCount,
            'header_lines' => $headerLinesCount,
            'total_chunks' => $totalChunks,
            'column_description' => $columnDescription,
        ]);

        if ($totalChunks === 1) {
            $emit(['event' => 'chunk_progress', 'current' => 1, 'total' => 1]);
            $allTransactions = $this->extractChunk($rawContent, $columnDescription);
            $emit(['event' => 'chunk_done', 'current' => 1, 'total' => 1, 'extracted' => count($allTransactions)]);
        } else {
            $allTransactions = [];

            for ($i = 0; $i < $totalChunks; $i++) {
                $chunk = array_slice($dataLines, $i * $chunkSize, $chunkSize);
                $chunkContent = implode("\n", $chunk);
                $chunkNumber = $i + 1;

                // Prepend real header lines so AI sees actual column names
                if ($headerContent !== '') {
                    $chunkContent = $headerContent."\n".$chunkContent;
                }

                $emit(['event' => 'chunk_progress', 'current' => $chunkNumber, 'total' => $totalChunks]);

                Log::info("Processing chunk {$chunkNumber}/{$totalChunks}", [
                    'lines' => count($chunk),
                ]);

                $transactions = $this->extractChunk($chunkContent, $columnDescription);
                $allTransactions = array_merge($allTransactions, $transactions);

                $emit(['event' => 'chunk_done', 'current' => $chunkNumber, 'total' => $totalChunks, 'extracted' => count($allTransactions)]);
            }
        }

        // Re-sequence all transactions
        foreach ($allTransactions as $i => &$t) {
            $t['sequence'] = $i + 1;
        }
        unset($t);

        Log::info('AI extracted transactions from bank statement', [
            'count' => count($allTransactions),
        ]);

        if (empty($allTransactions)) {
            throw new \RuntimeException('La IA no pudo extraer las transacciones del archivo.');
        }

        return $allTransactions;
    }

    /**
     * Send a content chunk to Gemini with column context and extract transactions.
     *
     * @return array<int, mixed>
     */
    protected function extractChunk(string $content, string $columnDescription = ''): array
    {
        $contextLine = $columnDescription !== ''
            ? "\n\nFORMATO DE COLUMNAS DEL ARCHIVO:\n{$columnDescription}\n\nUsa esta descripción para identificar correctamente cada campo."
            : '';

        $prompt = <<<PROMPT
Eres un experto en extractos bancarios. Extrae TODAS las transacciones de este fragmento.{$contextLine}

REGLAS:
1. Ignora encabezados del banco (nombre, saldos, fechas de descarga) y pies de página
2. Extrae SOLO filas de transacciones reales (con fecha, descripción y al menos un monto)
3. Fechas en formato YYYY-MM-DD (año de 2 dígitos "26" → "2026")
4. Montos como números decimales positivos, sin "\$", sin comas de miles
5. Cargo/débito → debit_amount (credit_amount = null)
6. Abono/crédito → credit_amount (debit_amount = null)
7. "memo" = descripción/concepto tal como aparece
8. "sequence" = número secuencial desde 1
9. Si no hay transacciones en este fragmento, devuelve un array vacío: []

Estructura JSON compacta:
[{"sequence":1,"due_date":"2026-02-17","memo":"Descripcion","debit_amount":100.50,"credit_amount":null}]
PROMPT;

        /** @var \Gemini\Client $gemini */
        $gemini = app('gemini');

        $result = $gemini->generativeModel('gemini-2.0-flash')
            ->withGenerationConfig(new GenerationConfig(
                responseMimeType: ResponseMimeType::APPLICATION_JSON,
                maxOutputTokens: 65536,
            ))
            ->generateContent([
                $prompt,
                new Blob(
                    mimeType: MimeType::TEXT_CSV,
                    data: base64_encode($content),
                ),
            ]);

        $finishReason = $result->candidates[0]->finishReason ?? null;
        $responseText = trim($result->text());

        Log::info('AI chunk response', [
            'finish_reason' => $finishReason?->value,
            'response_length' => strlen($responseText),
        ]);

        $decoded = json_decode($responseText, true);

        // If truncated, repair by closing the JSON array
        if ($decoded === null && $finishReason === FinishReason::MAX_TOKENS) {
            Log::warning('AI chunk truncated (MAX_TOKENS), repairing');
            $lastBrace = strrpos($responseText, '}');
            if ($lastBrace !== false) {
                $decoded = json_decode(substr($responseText, 0, $lastBrace + 1).']', true);
            }
        }

        // Handle wrapped object (e.g. {"transactions": [...]})
        if (is_array($decoded) && ! isset($decoded[0]) && ! isset($decoded['sequence'])) {
            foreach ($decoded as $value) {
                if (is_array($value) && isset($value[0]['sequence'])) {
                    return $value;
                }
            }
        }

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * Split a line by delimiter, handling potential edge cases.
     *
     * @return array<int, string>
     */
    protected function splitLine(string $line, string $delimiter): array
    {
        if ($delimiter === ',' || $delimiter === ';') {
            // Use str_getcsv for comma/semicolon to handle quoted fields
            return str_getcsv($line, $delimiter);
        }

        return explode($delimiter, $line);
    }

    /**
     * Clean a raw amount string into a float.
     * Handles: "$1,234.56" → 1234.56, "" → null, "0" → 0.0
     */
    protected function cleanAmount(string $raw): ?float
    {
        // Remove currency symbols, spaces, and thousand separators
        $cleaned = preg_replace('/[\s$€£¥,]/', '', $raw);

        // Handle parentheses as negative: (1234.56) → -1234.56
        if (preg_match('/^\((.+)\)$/', $cleaned, $m)) {
            $cleaned = '-'.$m[1];
        }

        if ($cleaned === '' || $cleaned === '-') {
            return null;
        }

        if (! is_numeric($cleaned)) {
            return null;
        }

        return (float) $cleaned;
    }

    /**
     * Parse a date string using the format hint from AI layout analysis.
     * Returns YYYY-MM-DD or null if parsing fails.
     */
    protected function parseDate(string $raw, string $format): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        try {
            // Normalize separators for matching
            $sep = str_contains($raw, '/') ? '/' : '-';
            $parts = explode($sep, $raw);

            if (count($parts) !== 3) {
                // Try Carbon as a last resort
                return Carbon::parse($raw)->format('Y-m-d');
            }

            switch ($format) {
                case 'DD/MM/YY':
                case 'DD-MM-YY':
                    $day = (int) $parts[0];
                    $month = (int) $parts[1];
                    $year = (int) $parts[2];
                    $year = $year < 100 ? $year + 2000 : $year;
                    break;

                case 'DD/MM/YYYY':
                case 'DD-MM-YYYY':
                    $day = (int) $parts[0];
                    $month = (int) $parts[1];
                    $year = (int) $parts[2];
                    break;

                case 'YYYY-MM-DD':
                    $year = (int) $parts[0];
                    $month = (int) $parts[1];
                    $day = (int) $parts[2];
                    break;

                case 'MM/DD/YY':
                case 'MM-DD-YY':
                    $month = (int) $parts[0];
                    $day = (int) $parts[1];
                    $year = (int) $parts[2];
                    $year = $year < 100 ? $year + 2000 : $year;
                    break;

                case 'MM/DD/YYYY':
                case 'MM-DD-YYYY':
                    $month = (int) $parts[0];
                    $day = (int) $parts[1];
                    $year = (int) $parts[2];
                    break;

                default:
                    // Assume DD/MM/YY as most common in Mexican banks
                    $day = (int) $parts[0];
                    $month = (int) $parts[1];
                    $year = (int) $parts[2];
                    $year = $year < 100 ? $year + 2000 : $year;
                    break;
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
            $data = $sheet->toArray();

            $lines = [];
            foreach ($data as $row) {
                $lines[] = implode("\t", array_map(fn ($cell) => (string) ($cell ?? ''), $row));
            }

            return implode("\n", $lines);
        }

        return file_get_contents($file->getPathname());
    }
}
