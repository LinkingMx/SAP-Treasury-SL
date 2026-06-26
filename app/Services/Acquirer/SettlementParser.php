<?php

namespace App\Services\Acquirer;

use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

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
     * Spanish month abbreviations → number (for combined datetimes like Rappi).
     *
     * @var array<string, int>
     */
    private const MONTHS_ES = [
        'ene' => 1, 'feb' => 2, 'mar' => 3, 'abr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'ago' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dic' => 12,
    ];

    /**
     * Date format hint that means "this column holds date AND time together"
     * (e.g. Rappi: "mié. 01 abr. 2026, 2:04:07 p. m.").
     */
    public const COMBINED_DATETIME = 'es_datetime';

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
     * Only the first $maxRows rows are loaded (memory-safe on large files).
     *
     * @return array{rows: array<int, array<int, string>>, header_row: int, delimiter: string}
     */
    public function readHeaders(UploadedFile $file, int $maxRows = 12): array
    {
        $rows = $this->readMatrix($file, $maxRows);

        return [
            'rows' => $rows,
            'header_row' => $this->detectHeaderRow($rows),
            'delimiter' => $this->delimiterFor($file),
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
     * @param  array{header_lines_count?: int, columns: array<string, array{index?: int, format?: string}>}  $parseConfig
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

        $matrix = $this->readMatrix($file);
        $headerLinesCount = (int) ($parseConfig['header_lines_count'] ?? 0);

        $rows = [];

        foreach (array_slice($matrix, $headerLinesCount) as $fields) {
            if (trim(implode('', $fields)) === '') {
                continue;
            }

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

            // Time comes from the date column (combined) or its own mapped column.
            $timeSource = $dateFormat === self::COMBINED_DATETIME
                ? ($fields[$dateIndex] ?? null)
                : $this->valueAt($fields, $columns, 'transaction_time');

            $rows[] = [
                'transaction_date' => $date,
                'transaction_time' => $this->parseTime($timeSource),
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
     * Read a spreadsheet/CSV into a 2D string matrix. Reads data only (no styles)
     * and, when $maxRows is set, only the first rows — so large files don't
     * exhaust memory. Excel date serials stay numeric and are resolved by parseDate.
     *
     * @return array<int, array<int, string>>
     */
    private function readMatrix(UploadedFile $file, ?int $maxRows = null): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (in_array($extension, ['xlsx', 'xls'], true)) {
            // PhpSpreadsheet is memory-hungry; the web pool defaults to 128M.
            if ($this->memoryLimitBytes() < 512 * 1024 * 1024) {
                @ini_set('memory_limit', '512M');
            }

            $reader = IOFactory::createReaderForFile($file->getPathname());
            $reader->setReadDataOnly(true);

            if ($maxRows !== null) {
                $reader->setReadFilter(new class($maxRows) implements IReadFilter
                {
                    public function __construct(private int $maxRows) {}

                    public function readCell($columnAddress, $row, $worksheetName = ''): bool
                    {
                        return $row <= $this->maxRows;
                    }
                });
            }

            $data = $reader->load($file->getPathname())->getActiveSheet()->toArray(null, false, false, false);

            return array_map(
                static fn ($row): array => array_map(static fn ($cell): string => trim((string) ($cell ?? '')), (array) $row),
                $data,
            );
        }

        $lines = [];
        $handle = fopen($file->getPathname(), 'r');
        if ($handle === false) {
            return [];
        }
        while (($line = fgets($handle)) !== false) {
            $lines[] = rtrim($line, "\r\n");
            if ($maxRows !== null && count($lines) >= $maxRows) {
                break;
            }
        }
        fclose($handle);

        $delimiter = $this->detectDelimiter($lines);

        return array_map(
            fn (string $line): array => array_map(static fn ($cell): string => trim((string) $cell), $this->splitLine($line, $delimiter)),
            $lines,
        );
    }

    /**
     * Current PHP memory_limit in bytes (-1/unlimited returns PHP_INT_MAX).
     */
    private function memoryLimitBytes(): int
    {
        $raw = trim((string) ini_get('memory_limit'));

        if ($raw === '' || $raw === '-1') {
            return PHP_INT_MAX;
        }

        $value = (int) $raw;

        return match (strtolower(substr($raw, -1))) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    /**
     * The delimiter to report for a file (tab for spreadsheets, detected for CSV).
     */
    private function delimiterFor(UploadedFile $file): string
    {
        if (in_array(strtolower($file->getClientOriginalExtension()), ['xlsx', 'xls'], true)) {
            return "\t";
        }

        $handle = fopen($file->getPathname(), 'r');
        $first = $handle ? rtrim((string) fgets($handle), "\r\n") : '';
        if ($handle) {
            fclose($handle);
        }

        return $this->detectDelimiter([$first]);
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
     * Detect the best-fit CSV delimiter from sample lines.
     *
     * @param  array<int, string>  $lines
     */
    private function detectDelimiter(array $lines): string
    {
        $firstNonEmpty = '';
        foreach ($lines as $line) {
            if (trim($line) !== '') {
                $firstNonEmpty = $line;

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

            // Combined Spanish datetime (Rappi): "mié. 01 abr. 2026, 2:04:07 p. m."
            if ($format === self::COMBINED_DATETIME || preg_match('/\b(ene|feb|mar|abr|may|jun|jul|ago|sep|oct|nov|dic)\b/iu', $raw)) {
                $spanish = $this->parseSpanishDate($raw);
                if ($spanish !== null) {
                    return $spanish;
                }
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
     * Parse a Spanish-style date embedded in a string (e.g. "mié. 01 abr. 2026,
     * 2:04:07 p. m." → 2026-04-01). Same logic that validated against gCore.
     */
    private function parseSpanishDate(string $raw): ?string
    {
        if (! preg_match('/(\d{1,2})\s+(ene|feb|mar|abr|may|jun|jul|ago|sep|oct|nov|dic)\.?\s+(\d{4})/iu', $raw, $m)) {
            return null;
        }

        $month = self::MONTHS_ES[strtolower($m[2])] ?? null;
        if ($month === null) {
            return null;
        }

        $day = (int) $m[1];
        $year = (int) $m[3];

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * Parse a time into HH:MM:SS. Handles 12-hour "9:11:07 p.m." / "2:04:07 p. m.",
     * 24-hour "21:11:07", and Excel time serials (fraction of a day). Null if none.
     */
    private function parseTime(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (is_numeric($raw) && (float) $raw >= 0 && (float) $raw < 1) {
            $secs = (int) round((float) $raw * 86400);

            return sprintf('%02d:%02d:%02d', intdiv($secs, 3600), intdiv($secs % 3600, 60), $secs % 60);
        }

        // 12-hour with am/pm marker.
        if (preg_match('/(\d{1,2}):(\d{2})(?::(\d{2}))?\s*([ap])\.?\s*m/iu', $raw, $m)) {
            $h = (int) $m[1];
            $min = (int) $m[2];
            $s = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : 0;
            $isPm = strtolower($m[4]) === 'p';
            if ($isPm && $h < 12) {
                $h += 12;
            }
            if (! $isPm && $h === 12) {
                $h = 0;
            }

            return $h <= 23 && $min <= 59 && $s <= 59 ? sprintf('%02d:%02d:%02d', $h, $min, $s) : null;
        }

        // 24-hour.
        if (preg_match('/(\d{1,2}):(\d{2})(?::(\d{2}))?/', $raw, $m)) {
            $h = (int) $m[1];
            $min = (int) $m[2];
            $s = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : 0;

            return $h <= 23 && $min <= 59 && $s <= 59 ? sprintf('%02d:%02d:%02d', $h, $min, $s) : null;
        }

        return null;
    }
}
