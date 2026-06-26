<?php

namespace App\Services\Acquirer;

use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Deterministic (no-AI) reader/parser for acquirer settlement files. Reads the
 * header row so the user can map columns manually, then extracts the rows using
 * that mapping. Output matches what SettlementIngestService::ingestRows expects.
 */
class SettlementParser
{
    /**
     * Header aliases (normalized: lowercase, no accents) used to auto-suggest a
     * mapping. First header that contains any alias wins for that field.
     *
     * @var array<string, array<int, string>>
     */
    private const ALIASES = [
        'transaction_date' => ['fecha de operacion', 'fecha operacion', 'fecha venta', 'fecha', 'date'],
        'transaction_time' => ['hora', 'time'],
        'amount' => ['importe', 'monto total', 'monto', 'total', 'amount'],
        'authorization' => ['numero de autorizacion', 'no de autorizacion', 'no autorizacion', 'autorizacion', 'auth', 'aut'],
        'reference' => ['referencia', 'reference', 'folio', 'orden', 'id'],
        'card_type' => ['tipo de tarjeta', 'tipo tarjeta', 'tipo'],
        'card_brand' => ['marca', 'franquicia', 'tarjeta'],
        'terminal' => ['terminal', 'tpv', 'afiliacion'],
        'operation_type' => ['tipo de operacion', 'operacion'],
        'status' => ['estatus', 'estado', 'status'],
    ];

    /**
     * The settlement fields a mapping can target.
     *
     * @var array<int, string>
     */
    public const FIELDS = [
        'transaction_date', 'transaction_time', 'amount', 'authorization',
        'reference', 'card_type', 'card_brand', 'terminal', 'operation_type', 'status',
    ];

    /**
     * Read the first rows of a file as a matrix so the UI can build a mapping.
     *
     * @return array{rows: array<int, array<int, string>>, header_row: int, delimiter: string}
     */
    public function readHeaders(UploadedFile $file, int $maxRows = 12): array
    {
        $text = $this->readFileAsText($file);
        $lines = array_slice(explode("\n", $text), 0, $maxRows);
        $delimiter = $this->detectDelimiter($file, $lines);

        $rows = [];
        foreach ($lines as $line) {
            $rows[] = array_map(
                static fn ($cell): string => trim((string) $cell),
                $this->splitLine(rtrim($line, "\r"), $delimiter),
            );
        }

        return [
            'rows' => $rows,
            'header_row' => $this->detectHeaderRow($rows),
            'delimiter' => $delimiter,
        ];
    }

    /**
     * Suggest a field => column-index mapping from the headers, preferring a
     * previously saved mapping (matched by header name), then header aliases.
     *
     * @param  array<int, string>  $headers
     * @param  array<string, mixed>|null  $savedMap  acquirer.column_map
     * @return array<string, int|null>
     */
    public function suggestMapping(array $headers, ?array $savedMap = null): array
    {
        $normalized = array_map(fn ($h): string => $this->normalize((string) $h), $headers);
        $mapping = array_fill_keys(self::FIELDS, null);

        if (is_array($savedMap['columns'] ?? null)) {
            foreach ($savedMap['columns'] as $field => $spec) {
                if (! array_key_exists($field, $mapping)) {
                    continue;
                }
                $header = is_array($spec) ? ($spec['header'] ?? null) : null;
                if ($header === null) {
                    continue;
                }
                $index = array_search($this->normalize((string) $header), $normalized, true);
                if ($index !== false) {
                    $mapping[$field] = $index;
                }
            }
        }

        foreach (self::ALIASES as $field => $aliases) {
            if ($mapping[$field] !== null) {
                continue;
            }
            foreach ($normalized as $index => $header) {
                if ($header === '') {
                    continue;
                }
                foreach ($aliases as $alias) {
                    if (str_contains($header, $alias)) {
                        $mapping[$field] = $index;

                        break 2;
                    }
                }
            }
        }

        return $mapping;
    }

    /**
     * Extract every settlement row using a manual column mapping (parse_config).
     *
     * @param  array{header_lines_count?: int, delimiter?: string, columns: array<string, array{index?: int, format?: string}>}  $parseConfig
     * @return array<int, array<string, mixed>>
     */
    public function parseRows(UploadedFile $file, array $parseConfig): array
    {
        $columns = $parseConfig['columns'] ?? [];

        $dateIndex = $columns['transaction_date']['index'] ?? null;
        $dateFormat = $columns['transaction_date']['format'] ?? 'DD/MM/YYYY';
        $amountIndex = $columns['amount']['index'] ?? null;

        if ($dateIndex === null || $amountIndex === null) {
            throw new \RuntimeException('El mapeo debe incluir al menos la fecha y el monto.');
        }

        $rawContent = $this->readFileAsText($file);
        $lines = explode("\n", $rawContent);
        $headerLinesCount = (int) ($parseConfig['header_lines_count'] ?? 0);
        $delimiter = $parseConfig['delimiter'] ?? "\t";
        if ($delimiter === '\t') {
            $delimiter = "\t";
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
            throw new \RuntimeException('No se pudieron extraer renglones del archivo con el mapeo indicado.');
        }

        Log::info('Settlement manual extraction complete', ['count' => count($rows)]);

        return $rows;
    }

    /**
     * Normalize a header for matching: lowercase, trimmed, accents stripped.
     */
    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return strtr($value, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);
    }

    /**
     * Detect the delimiter: tab for spreadsheets, best-fit for CSV.
     *
     * @param  array<int, string>  $lines
     */
    private function detectDelimiter(UploadedFile $file, array $lines): string
    {
        if (in_array(strtolower($file->getClientOriginalExtension()), ['xlsx', 'xls'], true)) {
            return "\t";
        }

        $firstNonEmpty = '';
        foreach ($lines as $line) {
            if (trim($line) !== '') {
                $firstNonEmpty = rtrim($line, "\r");

                break;
            }
        }

        $best = ',';
        $bestCount = 0;
        foreach ([',', ';', "\t", '|'] as $candidate) {
            $count = count($this->splitLine($firstNonEmpty, $candidate));
            if ($count > $bestCount) {
                $bestCount = $count;
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * Detect the header row: first row with at least two non-empty cells.
     *
     * @param  array<int, array<int, string>>  $rows
     */
    private function detectHeaderRow(array $rows): int
    {
        foreach ($rows as $index => $cells) {
            $nonEmpty = array_filter($cells, static fn ($c): bool => trim((string) $c) !== '');
            if (count($nonEmpty) >= 2) {
                return $index;
            }
        }

        return 0;
    }

    /**
     * Pull a trimmed string value for an optional column, or null if absent/empty.
     *
     * @param  array<int, string>  $fields
     * @param  array<string, mixed>  $columns
     */
    private function valueAt(array $fields, array $columns, string $key): ?string
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
    private function splitLine(string $line, string $delimiter): array
    {
        if ($delimiter === ',' || $delimiter === ';') {
            return str_getcsv($line, $delimiter);
        }

        return explode($delimiter, $line);
    }

    /**
     * Clean a raw amount string into a float. "$1,234.56" → 1234.56, "" → null.
     */
    private function cleanAmount(string $raw): ?float
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
    private function parseDate(string $raw, string $format): ?string
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
    private function readFileAsText(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (in_array($extension, ['xlsx', 'xls'], true)) {
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
