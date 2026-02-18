<?php

namespace App\Services\Ai;

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
Analiza este extracto bancario y devuelve un JSON con:
1. "bank_name_guess": nombre del banco detectado
2. "header_lines_count": número de líneas de encabezado antes de las transacciones (información del banco, títulos de columna, etc.)
3. "column_description": descripción textual del formato de las columnas de transacciones. Ejemplo: "Columna 1: Fecha DD/MM/YY, Columna 2: Concepto/Descripción, Columna 3: Cargo (débito) con prefijo $, Columna 4: Abono (crédito) con prefijo $, Columna 5: Saldo"

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
     * Step 2: Extract transactions — uses layout info from step 1 to process in chunks.
     *
     * @param  callable|null  $onProgress  fn(array $event): void — emits progress events
     * @return array<int, array{sequence: int, due_date: string, memo: string, debit_amount: float|null, credit_amount: float|null}>
     */
    public function parseTransactions(UploadedFile $file, array $parseConfig, ?callable $onProgress = null): array
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
