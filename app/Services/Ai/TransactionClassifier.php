<?php

namespace App\Services\Ai;

use App\Models\LearningRule;
use Gemini\Client as GeminiClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TransactionClassifier
{
    protected const CHUNK_SIZE = 50;

    /**
     * Classify transactions using rules and optionally AI.
     *
     * @param  array<int, array{sequence: int, due_date: string, memo: string, debit_amount: float|null, credit_amount: float|null}>  $transactions
     * @param  array<int, array{code: string, name: string}>  $chartOfAccounts
     * @param  bool  $rulesOnly  If true, skip AI classification and only use rules
     * @return array<int, array{sequence: int, due_date: string, memo: string, debit_amount: float|null, credit_amount: float|null, sap_account_code: string|null, sap_account_name: string|null, confidence: int, source: string}>
     */
    public function classify(array $transactions, array $chartOfAccounts, bool $rulesOnly = false): array
    {
        $classified = [];
        $unclassified = [];

        // First pass: try to classify using rules
        foreach ($transactions as $transaction) {
            $ruleMatch = $this->classifyWithRules($transaction['memo']);

            if ($ruleMatch) {
                $classified[] = array_merge($transaction, [
                    'sap_account_code' => $ruleMatch['code'],
                    'sap_account_name' => $ruleMatch['name'],
                    'confidence' => $ruleMatch['confidence'],
                    'source' => 'rule',
                ]);
            } else {
                $unclassified[] = $transaction;
            }
        }

        // Second pass: classify remaining with AI (unless rulesOnly mode)
        if (! empty($unclassified)) {
            if (! $rulesOnly && ! empty($chartOfAccounts)) {
                // Use AI classification with learned rules as context
                $aiClassified = $this->classifyWithAi($unclassified, $chartOfAccounts);
                $classified = array_merge($classified, $aiClassified);
            } else {
                // Rules only mode or no chart of accounts - mark as unclassified
                foreach ($unclassified as $transaction) {
                    $classified[] = array_merge($transaction, [
                        'sap_account_code' => null,
                        'sap_account_name' => null,
                        'confidence' => 0,
                        'source' => 'none',
                    ]);
                }
            }
        }

        // Sort by sequence
        usort($classified, fn ($a, $b) => $a['sequence'] <=> $b['sequence']);

        return $classified;
    }

    /**
     * Try to classify a transaction using learned rules.
     *
     * @return array{code: string, name: string|null, confidence: int}|null
     */
    public function classifyWithRules(string $description): ?array
    {
        $rule = LearningRule::findBestMatch($description);

        if ($rule) {
            return [
                'code' => $rule->sap_account_code,
                'name' => $rule->sap_account_name,
                'confidence' => $rule->confidence_score,
            ];
        }

        return null;
    }

    /**
     * Classify transactions using AI.
     *
     * @param  array<int, array>  $transactions
     * @param  array<int, array{code: string, name: string}>  $chartOfAccounts
     * @return array<int, array>
     */
    protected function classifyWithAi(array $transactions, array $chartOfAccounts): array
    {
        $classified = [];
        $chunks = array_chunk($transactions, self::CHUNK_SIZE);

        // Create a map of accounts for quick lookup
        $accountMap = [];
        foreach ($chartOfAccounts as $account) {
            $accountMap[$account['code']] = $account['name'];
        }

        // Limit chart of accounts for prompt (take most common ones or first 100)
        $limitedChartOfAccounts = array_slice($chartOfAccounts, 0, 100);

        foreach ($chunks as $chunk) {
            $chunkClassified = $this->classifyChunkWithAi($chunk, $limitedChartOfAccounts, $accountMap);
            $classified = array_merge($classified, $chunkClassified);
        }

        return $classified;
    }

    /**
     * Get learning rules formatted for AI context.
     *
     * @return array<int, array{pattern: string, account_code: string, account_name: string|null}>
     */
    protected function getLearningRulesForAi(): array
    {
        return LearningRule::query()
            ->where('confidence_score', '>=', 80)
            ->orderByDesc('confidence_score')
            ->limit(50)
            ->get(['pattern', 'sap_account_code', 'sap_account_name'])
            ->map(fn ($rule) => [
                'pattern' => $rule->pattern,
                'account_code' => $rule->sap_account_code,
                'account_name' => $rule->sap_account_name,
            ])
            ->toArray();
    }

    /**
     * Classify a chunk of transactions with AI.
     */
    protected function classifyChunkWithAi(array $chunk, array $chartOfAccounts, array $accountMap): array
    {
        $transactionsForPrompt = array_map(function ($t) {
            return [
                'sequence' => $t['sequence'],
                'memo' => $t['memo'],
                'debit' => $t['debit_amount'],
                'credit' => $t['credit_amount'],
            ];
        }, $chunk);

        $chartJson = json_encode($chartOfAccounts, JSON_UNESCAPED_UNICODE);
        $transactionsJson = json_encode($transactionsForPrompt, JSON_UNESCAPED_UNICODE);

        // Get learned rules as context for AI
        $learningRules = $this->getLearningRulesForAi();
        $rulesSection = '';
        if (! empty($learningRules)) {
            $rulesJson = json_encode($learningRules, JSON_UNESCAPED_UNICODE);
            $rulesSection = <<<RULES

REGLAS APRENDIDAS (clasificaciones previas confirmadas por el usuario - PRIORIDAD ALTA):
{$rulesJson}

IMPORTANTE: Si una descripción contiene un patrón de las reglas aprendidas, USA esa cuenta con confidence 95-100.
RULES;
        }

        $prompt = <<<PROMPT
Actúa como un Contador Senior experto en SAP Business One.
Objetivo: Asignar la cuenta contable correcta basándote en la descripción bancaria.

CATÁLOGO DE CUENTAS SAP (muestra):
{$chartJson}
{$rulesSection}

INPUT (Transacciones a clasificar):
{$transactionsJson}

REGLAS DE NEGOCIO:
1. Debit (Cargo) son Salidas (Gastos o Pagos). Credit (Abono) son Entradas.
2. PRIMERO verifica si la descripción coincide con alguna REGLA APRENDIDA. Si coincide, usa esa cuenta.
3. Palabras clave comunes:
   - "Comision", "IVA", "Renta TPV", "Manejo" -> Comisiones Bancarias o Gastos Financieros
   - "Nomina", "Payroll", "Sueldos" -> Nómina
   - "SPEI", "Transferencia" -> Analizar contexto del concepto
   - "UBER", "DIDI", "Gasolina" -> Gastos de Viaje o Transporte
   - "Deposito", "Abono" -> Generalmente Ingresos
   - "Pago", "Proveedor" -> Pagos a Proveedores
4. Si la descripción es ambigua o no puedes determinar la cuenta, devuelve sap_code: null.
5. Asigna confidence entre 0-100 basado en qué tan seguro estás.

OUTPUT JSON (Array - solo el JSON, sin texto adicional):
[
  { "sequence": 1, "sap_code": "600-10", "confidence": 95 },
  { "sequence": 2, "sap_code": null, "confidence": 0 }
]

Responde SOLO con el array JSON, sin explicaciones.
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

            $aiResults = json_decode($responseText, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('Failed to parse AI classification response', [
                    'response' => $responseText,
                    'error' => json_last_error_msg(),
                ]);

                return $this->markChunkAsUnclassified($chunk);
            }

            // Map AI results back to transactions
            $aiResultsMap = [];
            foreach ($aiResults as $result) {
                $aiResultsMap[$result['sequence']] = $result;
            }

            $classified = [];
            foreach ($chunk as $transaction) {
                $aiResult = $aiResultsMap[$transaction['sequence']] ?? null;

                // Validate that the suggested account exists in the chart of accounts
                $suggestedCode = $aiResult['sap_code'] ?? null;
                $accountExists = $suggestedCode && isset($accountMap[$suggestedCode]);

                if ($aiResult && $accountExists) {
                    $classified[] = array_merge($transaction, [
                        'sap_account_code' => $suggestedCode,
                        'sap_account_name' => $accountMap[$suggestedCode],
                        'confidence' => $aiResult['confidence'] ?? 80,
                        'source' => 'ai',
                    ]);
                } else {
                    // Account doesn't exist or AI couldn't classify - mark as unclassified
                    $classified[] = array_merge($transaction, [
                        'sap_account_code' => null,
                        'sap_account_name' => null,
                        'confidence' => 0,
                        'source' => $suggestedCode ? 'ai' : 'none',
                    ]);
                }
            }

            return $classified;

        } catch (\Exception $e) {
            Log::error('AI classification failed', ['error' => $e->getMessage()]);

            return $this->markChunkAsUnclassified($chunk);
        }
    }

    /**
     * Mark a chunk of transactions as unclassified.
     */
    protected function markChunkAsUnclassified(array $chunk): array
    {
        return array_map(function ($transaction) {
            return array_merge($transaction, [
                'sap_account_code' => null,
                'sap_account_name' => null,
                'confidence' => 0,
                'source' => 'error',
            ]);
        }, $chunk);
    }

    /**
     * Get chart of accounts from SAP via direct SQL query (cached for 1 hour).
     *
     * @return array<int, array{code: string, name: string}>
     */
    public function fetchChartOfAccounts(string $companyDB): array
    {
        $cacheKey = "sap_chart_of_accounts_{$companyDB}";

        return Cache::remember($cacheKey, 3600, function () use ($companyDB) {
            try {
                // Configure database connection for the specific SAP company
                config(['database.connections.sap_sqlsrv.database' => $companyDB]);
                DB::purge('sap_sqlsrv');

                // Query chart of accounts directly from SAP database
                $accounts = DB::connection('sap_sqlsrv')
                    ->table('OACT')
                    ->select(['AcctCode as code', 'AcctName as name'])
                    ->orderBy('AcctCode')
                    ->get()
                    ->map(fn ($item) => [
                        'code' => $item->code,
                        'name' => $item->name,
                    ])
                    ->toArray();

                Log::info('Chart of accounts fetched via SQL', [
                    'companyDB' => $companyDB,
                    'count' => count($accounts),
                ]);

                return $accounts;
            } catch (\Exception $e) {
                Log::error('Failed to fetch chart of accounts from SAP SQL', [
                    'companyDB' => $companyDB,
                    'error' => $e->getMessage(),
                ]);

                return [];
            }
        });
    }

    /**
     * Clear the cached chart of accounts.
     */
    public function clearChartOfAccountsCache(string $companyDB): void
    {
        Cache::forget("sap_chart_of_accounts_{$companyDB}");
    }
}
