<?php

namespace App\Services\Ai;

use App\Models\Branch;
use App\Models\LearningRule;
use App\Models\SapAccount;
use Gemini\Client as GeminiClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TransactionClassifier
{
    protected const CHUNK_SIZE = 200;

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
                // Get transactions classified by rules as context for AI
                $ruleClassified = array_filter($classified, fn ($t) => $t['source'] === 'rule');
                // Use AI classification with learned rules AND rule-classified transactions as context
                $aiClassified = $this->classifyWithAi($unclassified, $chartOfAccounts, array_values($ruleClassified));
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
     * @param  array<int, array>  $ruleClassified  Transactions already classified by rules (context for AI)
     * @return array<int, array>
     */
    protected function classifyWithAi(array $transactions, array $chartOfAccounts, array $ruleClassified = []): array
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
            $chunkClassified = $this->classifyChunkWithAi($chunk, $limitedChartOfAccounts, $accountMap, $ruleClassified);
            $classified = array_merge($classified, $chunkClassified);
        }

        return $classified;
    }

    /**
     * Get learning rules formatted for AI context (high confidence only).
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
     * Get ALL learning rules with full pattern text for AI pattern matching.
     *
     * @return array<int, array{pattern: string, sap_code: string, sap_name: string|null}>
     */
    protected function getAllLearningRulesForAi(): array
    {
        return LearningRule::query()
            ->orderByDesc('confidence_score')
            ->get(['pattern', 'sap_account_code', 'sap_account_name'])
            ->map(fn ($rule) => [
                'pattern' => $rule->pattern,
                'sap_code' => $rule->sap_account_code,
                'sap_name' => $rule->sap_account_name,
            ])
            ->toArray();
    }

    /**
     * Extract RFC from a bank memo if present.
     */
    protected function extractRfc(string $memo): ?string
    {
        // RFC pattern: 3-4 letters + 6 digits + 3 alphanumeric (12-13 chars total)
        if (preg_match('/RFC\s+([A-Z]{3,4}\d{6}[A-Z0-9]{3})/i', $memo, $matches)) {
            return strtoupper($matches[1]);
        }
        // Also try without RFC prefix
        if (preg_match('/\b([A-Z]{3,4}\d{6}[A-Z0-9]{3})\b/', $memo, $matches)) {
            return strtoupper($matches[1]);
        }

        return null;
    }

    /**
     * Extract keywords from a bank memo for matching.
     */
    protected function extractKeywords(string $memo): array
    {
        $keywords = [];
        $text = strtoupper($memo);

        // High priority: Company/service identifiers
        $companyKeywords = [
            'UBR PAGOS', 'UBER EATS', 'UBER',
            'RAPPI', 'RAPPIPAYMENT', 'TECNOLOGIAS RAPPI',
            'DIDI', 'CLIP', 'MERCADO PAGO',
        ];

        foreach ($companyKeywords as $kw) {
            if (str_contains($text, $kw)) {
                $keywords[] = $kw;
            }
        }

        // Medium priority: Transaction type keywords
        $typeKeywords = [
            'COMISION', 'IVA POR COMISION', 'IVA',
            'RENTA TPV', 'RENTA TERMINAL',
            'NOMINA', 'PAYROLL', 'AGUINALDO',
            'DEPOSITO EN EFECTIVO', 'DEPOSITO VENTAS DEL DIA',
        ];

        foreach ($typeKeywords as $kw) {
            if (str_contains($text, $kw)) {
                $keywords[] = $kw;
            }
        }

        // Low priority: Generic movement types
        if (str_contains($text, 'SPEI RECIBIDO')) {
            $keywords[] = 'SPEI RECIBIDO';
        } elseif (str_contains($text, 'SPEI ENVIADO')) {
            $keywords[] = 'SPEI ENVIADO';
        } elseif (str_contains($text, 'TRANSFERENCIA')) {
            $keywords[] = 'TRANSFERENCIA';
        } elseif (str_contains($text, 'DEPOSITO')) {
            $keywords[] = 'DEPOSITO';
        }

        return array_unique($keywords);
    }

    /**
     * Determine movement type from memo and amounts.
     */
    protected function getMovementType(?float $debit, ?float $credit): string
    {
        if ($debit !== null && $debit > 0) {
            return 'CARGO';
        }
        if ($credit !== null && $credit > 0) {
            return 'ABONO';
        }

        return 'DESCONOCIDO';
    }

    /**
     * Get movement type from memo text.
     */
    protected function getMovementTypeFromMemo(string $memo): string
    {
        $text = strtoupper($memo);
        if (str_contains($text, 'SPEI RECIBIDO') || str_contains($text, 'DEPOSITO') || str_contains($text, 'ABONO')) {
            return 'ABONO';
        }
        if (str_contains($text, 'SPEI ENVIADO') || str_contains($text, 'PAGO') || str_contains($text, 'CARGO')) {
            return 'CARGO';
        }

        return 'DESCONOCIDO';
    }

    /**
     * Classify a chunk of transactions with AI using pattern matching against learned rules.
     *
     * @param  array<int, array>  $chunk
     * @param  array<int, array{code: string, name: string}>  $chartOfAccounts
     * @param  array<string, string>  $accountMap
     * @param  array<int, array>  $ruleClassified  Transactions already classified by rules (context)
     */
    protected function classifyChunkWithAi(array $chunk, array $chartOfAccounts, array $accountMap, array $ruleClassified = []): array
    {
        // Get ALL learned rules
        $learningRules = $this->getAllLearningRulesForAi();

        // If no rules exist, we can't do pattern matching
        if (empty($learningRules)) {
            Log::info('No learning rules available for AI pattern matching');

            return $this->markChunkAsUnclassified($chunk);
        }

        // Build rules table with RFC, keywords, and movement type
        $rulesTable = "| ID | CUENTA_SAP   | RFC          | KEYWORDS                    | TIPO_MOV |\n";
        $rulesTable .= '|'.str_repeat('-', 80)."|\n";
        foreach ($learningRules as $i => $rule) {
            $rfc = $this->extractRfc($rule['pattern']) ?? 'NULL';
            $keywords = $this->extractKeywords($rule['pattern']);
            $keywordsStr = ! empty($keywords) ? implode(', ', array_slice($keywords, 0, 3)) : 'NULL';
            $movType = $this->getMovementTypeFromMemo($rule['pattern']);
            $rulesTable .= sprintf("| %-2d | %-12s | %-12s | %-27s | %-8s |\n",
                $i + 1, $rule['sap_code'], $rfc, substr($keywordsStr, 0, 27), $movType);
        }

        // Build transactions table with amount and extracted info
        $transTable = "| SEQ | MONTO       | RFC          | KEYWORDS                    | TIPO_MOV |\n";
        $transTable .= '|'.str_repeat('-', 85)."|\n";
        foreach ($chunk as $t) {
            $amount = ($t['debit_amount'] ?? 0) > 0 ? -($t['debit_amount']) : ($t['credit_amount'] ?? 0);
            $rfc = $this->extractRfc($t['memo']) ?? 'NULL';
            $keywords = $this->extractKeywords($t['memo']);
            $keywordsStr = ! empty($keywords) ? implode(', ', array_slice($keywords, 0, 3)) : 'NULL';
            $movType = $this->getMovementType($t['debit_amount'], $t['credit_amount']);
            $transTable .= sprintf("| %-3d | %10.2f | %-12s | %-27s | %-8s |\n",
                $t['sequence'], $amount, $rfc, substr($keywordsStr, 0, 27), $movType);
        }

        $prompt = <<<PROMPT
ROL: Eres un Asistente Contable. Asigna cuentas SAP a transacciones usando reglas jerárquicas.

REGLAS APRENDIDAS (prioridad: RFC > KEYWORDS > TIPO_MOV):
{$rulesTable}

TRANSACCIONES A CLASIFICAR:
{$transTable}

INSTRUCCIONES:
1. MONTO negativo = CARGO (egreso), positivo = ABONO (ingreso)
2. Busca en orden estricto:
   a) ¿RFC coincide exacto? → Usa esa cuenta (conf: 95)
   b) ¿KEYWORDS coinciden? → Usa esa cuenta (conf: 85)
   c) ¿Solo TIPO_MOV coincide? → Usa cuenta genérica (conf: 70)
3. Si no hay match, devuelve sap: null

RESPONDE solo JSON array minificado:
[{"seq":1,"sap":"1010-000-000","conf":95},{"seq":2,"sap":null,"conf":0}]
PROMPT;

        // Log the prompt for debugging
        Log::info('AI Pattern Matching Request', [
            'transactions_count' => count($chunk),
            'rules_count' => count($learningRules),
            'prompt_length' => strlen($prompt),
        ]);

        // Save full prompt to file for debugging
        file_put_contents(storage_path('logs/ai_prompt.txt'), $prompt);

        try {
            /** @var GeminiClient $gemini */
            $gemini = app('gemini');
            $result = $gemini->generativeModel('gemini-2.0-flash')->generateContent($prompt);
            $responseText = $result->text();

            // Save AI response to file for debugging
            file_put_contents(storage_path('logs/ai_response.txt'), $responseText);

            Log::info('AI Classification Response', [
                'response_length' => strlen($responseText),
                'response' => $responseText,
            ]);

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

            // Map AI results back to transactions (handle both old and new format)
            $aiResultsMap = [];
            foreach ($aiResults as $result) {
                $seq = $result['seq'] ?? $result['sequence'] ?? null;
                if ($seq !== null) {
                    $aiResultsMap[$seq] = [
                        'sap_code' => $result['sap'] ?? $result['sap_code'] ?? null,
                        'confidence' => $result['conf'] ?? $result['confidence'] ?? 0,
                    ];
                }
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
     * Get chart of accounts for a branch (local database only).
     *
     * @return array<int, array{code: string, name: string}>
     */
    public function getChartOfAccounts(Branch $branch): array
    {
        $localAccounts = SapAccount::getChartOfAccounts($branch->id);

        Log::info('Using local SAP accounts', [
            'branch_id' => $branch->id,
            'branch_name' => $branch->name,
            'count' => count($localAccounts),
        ]);

        return $localAccounts;
    }

    /**
     * Get chart of accounts from SAP via direct SQL query (cached for 1 hour).
     *
     * @return array<int, array{code: string, name: string}>
     */
    public function fetchChartOfAccounts(string $companyDB): array
    {
        $cacheKey = "sap_chart_of_accounts_{$companyDB}";

        // Check if we have a valid cached result
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && ! empty($cached)) {
            return $cached;
        }

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

            // Only cache if we got valid results
            if (! empty($accounts)) {
                Cache::put($cacheKey, $accounts, 3600);
            }

            return $accounts;
        } catch (\Exception $e) {
            Log::error('Failed to fetch chart of accounts from SAP SQL', [
                'companyDB' => $companyDB,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Clear the cached chart of accounts.
     */
    public function clearChartOfAccountsCache(string $companyDB): void
    {
        Cache::forget("sap_chart_of_accounts_{$companyDB}");
    }
}
