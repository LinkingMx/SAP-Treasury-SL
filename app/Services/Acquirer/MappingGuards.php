<?php

namespace App\Services\Acquirer;

/**
 * Sanity checks on a column mapping, using only the file itself — no AI.
 *
 * The alias matcher picks columns by substring, which fails silently on wide
 * acquirer exports: "Tiempo total de entrega" wins the `amount` field because it
 * contains "total", so delivery times would be imported as money. These guards
 * are the safety net that still works when Gemini is unavailable.
 */
final class MappingGuards
{
    /**
     * Headers that almost never hold the settled amount, even when their text
     * matches an amount alias.
     */
    private const AMOUNT_TRAPS = [
        'tiempo' => 'un tiempo o duración',
        'duracion' => 'una duración',
        'hora' => 'una hora',
        'tasa' => 'una tasa, no un importe',
        'comision' => 'la comisión del adquirente',
        'descuento' => 'un descuento o comisión',
        'iva' => 'un impuesto, no la venta',
        'propina' => 'la propina',
        'retencion' => 'una retención',
    ];

    /**
     * Inspect a mapping against the parsed sample and return human warnings.
     *
     * @param  array<string, array{index?: int, header?: string, format?: string}>  $columns  parse_config columns
     * @param  array<int, array<string, mixed>>  $sample  rows already parsed by SettlementParser
     * @return array<int, array{field: string, level: string, message: string}>
     */
    public function check(array $columns, array $sample): array
    {
        $warnings = [];

        foreach ($this->checkAmount($columns, $sample) as $warning) {
            $warnings[] = $warning;
        }

        foreach (['reference', 'authorization'] as $field) {
            $warning = $this->checkNotADate($field, $columns, $sample);
            if ($warning !== null) {
                $warnings[] = $warning;
            }
        }

        return $warnings;
    }

    /**
     * @param  array<string, array{index?: int, header?: string}>  $columns
     * @param  array<int, array<string, mixed>>  $sample
     * @return array<int, array{field: string, level: string, message: string}>
     */
    private function checkAmount(array $columns, array $sample): array
    {
        $header = trim((string) ($columns['amount']['header'] ?? ''));
        if ($header === '') {
            return [];
        }

        $warnings = [];
        $normalized = $this->normalize($header);

        foreach (self::AMOUNT_TRAPS as $needle => $meaning) {
            if (str_contains($normalized, $needle)) {
                $warnings[] = [
                    'field' => 'amount',
                    'level' => 'warning',
                    'message' => "«{$header}» parece {$meaning}. Verifica que sea la columna del importe de la venta.",
                ];

                break;
            }
        }

        $values = array_filter(
            array_map(static fn (array $row) => $row['amount'] ?? null, $sample),
            static fn ($v): bool => $v !== null,
        );

        if ($values === []) {
            $warnings[] = [
                'field' => 'amount',
                'level' => 'warning',
                'message' => "No se encontró ningún importe válido en «{$header}» en las filas de muestra.",
            ];
        }

        return $warnings;
    }

    /**
     * A reference or authorization whose sample values all parse as dates is
     * almost certainly the wrong column (aliases match "orden" inside
     * "Fecha de creación orden").
     *
     * @param  array<string, array{index?: int, header?: string}>  $columns
     * @param  array<int, array<string, mixed>>  $sample
     * @return array{field: string, level: string, message: string}|null
     */
    private function checkNotADate(string $field, array $columns, array $sample): ?array
    {
        $header = trim((string) ($columns[$field]['header'] ?? ''));
        if ($header === '' || $sample === []) {
            return null;
        }

        $values = array_values(array_filter(
            array_map(static fn (array $row) => $row[$field] ?? null, $sample),
            static fn ($v): bool => $v !== null && trim((string) $v) !== '',
        ));

        if ($values === []) {
            return null;
        }

        foreach ($values as $value) {
            if (! $this->looksLikeDate((string) $value)) {
                return null;
            }
        }

        $label = $field === 'reference' ? 'La referencia' : 'La autorización';

        return [
            'field' => $field,
            'level' => 'warning',
            'message' => "{$label} está tomando «{$header}», cuyos valores parecen fechas. Revisa si existe una columna de folio u orden.",
        ];
    }

    private function looksLikeDate(string $value): bool
    {
        return preg_match('#^\d{1,4}[/-]\d{1,2}[/-]\d{1,4}#', trim($value)) === 1;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return strtr($value, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);
    }
}
