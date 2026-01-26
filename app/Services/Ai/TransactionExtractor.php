<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Log;

/**
 * MÓDULO B: EL EXTRACTOR
 *
 * Procesa transacciones raw y devuelve datos normalizados.
 * - Limpia memos con regex del Arquitecto
 * - Extrae: actor, RFC, concepto
 * - Normaliza montos con signo
 */
class TransactionExtractor
{
    /**
     * Default noise patterns to remove from memos.
     */
    protected array $defaultNoisePatterns = [
        '/RASTREO\s+[A-Z0-9]+/i',
        '/REFERENCIA\s+[A-Z0-9]+/i',
        '/REF\.?\s*[A-Z0-9]+/i',
        '/HORA:?\s*\d{2}:\d{2}(:\d{2})?/i',
        '/FOLIO\s*:?\s*\d+/i',
        '/CIE\s*:?\s*\d+/i',
        '/DATO NO VERIFICADO[^,]*/i',
        '/A LA CTA\.?\s*CLABE[^,]*/i',
        '/DE LA CTA\.?\s*CLABE[^,]*/i',
        '/\d{10,}/i', // Long numbers (except RFCs which are 12-13 chars with letters)
    ];

    /**
     * Default patterns to extract actor from memo.
     */
    protected array $defaultActorPatterns = [
        '/RECIBIDO DE\s+\d+-[A-Z]+\s+DE\s+(.+?)(?:\s+RFC|\s+CONCEPTO|$)/i',
        '/ENVIADO A\s+\d+-[A-Z]+\s+A\s+(.+?)(?:\s+RFC|\s+CONCEPTO|$)/i',
        '/ORDENANTE:?\s*(.+?)(?:\s+RFC|\s+CONCEPTO|$)/i',
        '/BENEFICIARIO:?\s*(.+?)(?:\s+RFC|\s+CONCEPTO|$)/i',
    ];

    /**
     * Known concepts to extract.
     */
    protected array $knownConcepts = [
        'NOMINA',
        'AGUINALDO',
        'COMISION',
        'IVA POR COMISION',
        'IVA',
        'DEPOSITO EN EFECTIVO',
        'DEPOSITO VENTAS',
        'RETIRO EFECTIVO',
        'RENTA TPV',
        'RENTA TERMINAL',
        'TPV',
        'PAGO SERVICIOS',
        'PAGO IMPUESTOS',
        'TRANSFERENCIA',
    ];

    /**
     * Corporate suffixes to remove from actor names.
     */
    protected array $corporateSuffixes = [
        'SA DE CV',
        'SAPI DE CV',
        'S DE RL DE CV',
        'S DE RL',
        'SC DE RL',
        'SC',
        'SA',
        'AC',
    ];

    /**
     * Extract and normalize transactions.
     *
     * @param  array<int, array>  $rawTransactions  Raw transactions from parser
     * @param  array  $extractionConfig  Config from Architect (noise_patterns, actor_patterns)
     * @return array<int, array> Normalized transactions
     */
    public function extract(array $rawTransactions, array $extractionConfig = []): array
    {
        $noisePatterns = $extractionConfig['noise_patterns'] ?? $this->defaultNoisePatterns;
        $actorPatterns = $extractionConfig['actor_patterns'] ?? $this->defaultActorPatterns;

        $normalized = [];

        foreach ($rawTransactions as $tx) {
            $cleanedMemo = $this->cleanMemo($tx['memo'], $noisePatterns);
            $actor = $this->extractActor($tx['memo'], $actorPatterns); // Use original for actor
            $rfc = $this->extractRfc($tx['memo']);
            $concept = $this->extractConcept($cleanedMemo);

            $debit = $tx['debit_amount'] ?? null;
            $credit = $tx['credit_amount'] ?? null;

            $normalized[] = [
                'sequence' => $tx['sequence'],
                'due_date' => $tx['due_date'],
                'raw_memo' => $tx['memo'],
                'clean_memo' => $cleanedMemo,
                'actor' => $actor,
                'rfc' => $rfc,
                'concept' => $concept,
                'debit_amount' => $debit,
                'credit_amount' => $credit,
                'amount' => $debit ? -$debit : $credit,
                'type' => $debit ? 'CARGO' : 'ABONO',
            ];
        }

        Log::info('Transactions extracted', [
            'total' => count($normalized),
            'with_actor' => count(array_filter($normalized, fn ($t) => $t['actor'] !== null)),
            'with_rfc' => count(array_filter($normalized, fn ($t) => $t['rfc'] !== null)),
            'with_concept' => count(array_filter($normalized, fn ($t) => $t['concept'] !== null)),
        ]);

        return $normalized;
    }

    /**
     * Clean memo by removing noise patterns.
     */
    public function cleanMemo(string $memo, array $noisePatterns): string
    {
        $clean = $memo;

        foreach ($noisePatterns as $pattern) {
            $clean = preg_replace($pattern, '', $clean);
        }

        // Normalize whitespace
        $clean = trim(preg_replace('/\s+/', ' ', $clean));

        return $clean;
    }

    /**
     * Extract actor (company/person name) from memo.
     */
    public function extractActor(string $memo, array $actorPatterns): ?string
    {
        foreach ($actorPatterns as $pattern) {
            if (preg_match($pattern, $memo, $matches)) {
                $actor = trim($matches[1]);
                if (strlen($actor) >= 3) {
                    return $this->cleanActorName($actor);
                }
            }
        }

        return null;
    }

    /**
     * Clean actor name by removing corporate suffixes.
     */
    public function cleanActorName(string $name): string
    {
        $clean = strtoupper(trim($name));

        foreach ($this->corporateSuffixes as $suffix) {
            $pattern = '/\s*'.preg_quote($suffix, '/').'\s*$/i';
            $clean = preg_replace($pattern, '', $clean);
        }

        return trim($clean);
    }

    /**
     * Extract RFC from memo if present.
     */
    public function extractRfc(string $memo): ?string
    {
        // RFC: 3-4 letters + 6 digits + 3 alphanumeric (12-13 chars)
        // Skip "RFC ND" (no disponible)
        if (preg_match('/RFC\s+ND\b/i', $memo)) {
            return null;
        }

        if (preg_match('/RFC\s*:?\s*([A-Z]{3,4}\d{6}[A-Z0-9]{3})/i', $memo, $matches)) {
            return strtoupper($matches[1]);
        }

        // Try without RFC prefix (standalone RFC format)
        if (preg_match('/\b([A-Z]{3,4}\d{6}[A-Z0-9]{3})\b/', strtoupper($memo), $matches)) {
            // Validate it looks like an RFC (not just random alphanumeric)
            $potential = $matches[1];
            if (preg_match('/^[A-Z]{3,4}\d{6}[A-Z0-9]{3}$/', $potential)) {
                return $potential;
            }
        }

        return null;
    }

    /**
     * Extract concept/operation type from memo.
     */
    public function extractConcept(string $memo): ?string
    {
        $text = strtoupper($memo);

        // Check for known concepts (longer matches first)
        usort($this->knownConcepts, fn ($a, $b) => strlen($b) - strlen($a));

        foreach ($this->knownConcepts as $concept) {
            if (str_contains($text, $concept)) {
                return $concept;
            }
        }

        return null;
    }

    /**
     * Build keywords for rule matching from extracted data.
     */
    public function buildKeywords(?string $actor, ?string $rfc, ?string $concept): string
    {
        $parts = [];

        if ($actor) {
            $parts[] = $actor;
        }

        if ($concept && $concept !== 'TRANSFERENCIA') {
            $parts[] = $concept;
        }

        if (empty($parts) && $rfc) {
            $parts[] = 'RFC:'.$rfc;
        }

        return implode(', ', $parts) ?: 'SIN IDENTIFICAR';
    }
}
