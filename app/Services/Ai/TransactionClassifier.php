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
    protected const CHUNK_SIZE = 50; // Reduced to avoid API timeout

    /**
     * Fallback accounts for unclassified transactions.
     */
    protected const FALLBACK_INGRESO = '1050-002-000';

    protected const FALLBACK_EGRESO = '1000-001-000';

    /**
     * Classify normalized transactions using hierarchical rules.
     *
     * @param  array<int, array>  $normalizedTransactions  Output from TransactionExtractor
     * @param  array<int, array{code: string, name: string}>  $chartOfAccounts
     * @param  bool  $rulesOnly  If true, skip AI classification
     * @return array<int, array> Classified transactions
     */
    public function classifyNormalized(array $normalizedTransactions, array $chartOfAccounts, bool $rulesOnly = false): array
    {
        $classified = [];
        $unclassified = [];

        // First pass: Hierarchical rule matching
        foreach ($normalizedTransactions as $tx) {
            $match = LearningRule::findHierarchicalMatch(
                $tx['rfc'],
                $tx['actor'],
                $tx['concept'],
                $tx['clean_memo']
            );

            if ($match['rule']) {
                $classified[] = array_merge($tx, [
                    'sap_account_code' => $match['rule']->sap_account_code,
                    'sap_account_name' => $match['rule']->sap_account_name,
                    'confidence' => $match['confidence'],
                    'classification_level' => $match['level'],
                    'source' => 'rule', // Frontend expects 'rule', not 'rule_L1'
                ]);
            } else {
                $unclassified[] = $tx;
            }
        }

        // Second pass: AI classification for remaining (if enabled)
        if (! empty($unclassified)) {
            if (! $rulesOnly && ! empty($chartOfAccounts)) {
                $aiClassified = $this->classifyWithAi(
                    array_map(fn ($tx) => [
                        'sequence' => $tx['sequence'],
                        'due_date' => $tx['due_date'],
                        'memo' => $tx['raw_memo'],
                        'debit_amount' => $tx['debit_amount'],
                        'credit_amount' => $tx['credit_amount'],
                    ], $unclassified),
                    $chartOfAccounts,
                    $classified
                );

                // Merge AI results back with normalized data
                foreach ($aiClassified as $aiTx) {
                    $original = array_filter($unclassified, fn ($u) => $u['sequence'] === $aiTx['sequence']);
                    $original = reset($original);
                    if ($original) {
                        $classified[] = array_merge($original, [
                            'sap_account_code' => $aiTx['sap_account_code'],
                            'sap_account_name' => $aiTx['sap_account_name'],
                            'confidence' => $aiTx['confidence'],
                            'classification_level' => 4,
                            'source' => $aiTx['source'],
                        ]);
                    }
                }
            } else {
                // Apply fallback accounts based on movement type
                foreach ($unclassified as $tx) {
                    $fallbackAccount = $tx['type'] === 'ABONO' ? self::FALLBACK_INGRESO : self::FALLBACK_EGRESO;
                    $classified[] = array_merge($tx, [
                        'sap_account_code' => $fallbackAccount,
                        'sap_account_name' => 'Por Identificar',
                        'confidence' => 50,
                        'classification_level' => 5,
                        'source' => 'fallback',
                    ]);
                }
            }
        }

        // Sort by sequence
        usort($classified, fn ($a, $b) => $a['sequence'] <=> $b['sequence']);

        Log::info('Classification complete', [
            'total' => count($classified),
            'by_level' => [
                'L1_rfc_actor' => count(array_filter($classified, fn ($t) => ($t['classification_level'] ?? 0) === 1)),
                'L2_concept' => count(array_filter($classified, fn ($t) => ($t['classification_level'] ?? 0) === 2)),
                'L3_pattern' => count(array_filter($classified, fn ($t) => ($t['classification_level'] ?? 0) === 3)),
                'L4_ai' => count(array_filter($classified, fn ($t) => ($t['classification_level'] ?? 0) === 4)),
                'L5_fallback' => count(array_filter($classified, fn ($t) => ($t['classification_level'] ?? 0) === 5)),
            ],
        ]);

        return $classified;
    }

    /**
     * Legacy: Classify transactions using rules and optionally AI.
     *
     * @param  array<int, array{sequence: int, due_date: string, memo: string, debit_amount: float|null, credit_amount: float|null}>  $transactions
     * @param  array<int, array{code: string, name: string}>  $chartOfAccounts
     * @param  bool  $rulesOnly  If true, skip AI classification and only use rules
     * @return array<int, array{sequence: int, due_date: string, memo: string, debit_amount: float|null, credit_amount: float|null, sap_account_code: string|null, sap_account_name: string|null, confidence: int, source: string}>
     */
    public function classify(array $transactions, array $chartOfAccounts, bool $rulesOnly = false): array
    {
        // Use new pipeline: Extract then Classify
        $extractor = new TransactionExtractor;
        $normalized = $extractor->extract($transactions);

        return $this->classifyNormalized($normalized, $chartOfAccounts, $rulesOnly);
    }

    /**
     * Try to classify a transaction using hierarchical rules.
     *
     * @return array{code: string, name: string|null, confidence: int, level: int}|null
     */
    public function classifyWithRules(string $description): ?array
    {
        // Quick extraction for single memo
        $extractor = new TransactionExtractor;
        $rfc = $extractor->extractRfc($description);
        $actor = $extractor->extractActor($description, []);
        $concept = $extractor->extractConcept($description);

        $match = LearningRule::findHierarchicalMatch($rfc, $actor, $concept, $description);

        if ($match['rule']) {
            return [
                'code' => $match['rule']->sap_account_code,
                'name' => $match['rule']->sap_account_name,
                'confidence' => $match['confidence'],
                'level' => $match['level'],
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
     * Get account name from learning rules by account code.
     */
    protected function getAccountNameFromRules(string $code): ?string
    {
        $rule = LearningRule::where('sap_account_code', $code)->first();

        return $rule?->sap_account_name;
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
     * Classify a chunk of transactions with AI using ONLY learned rules.
     *
     * @param  array<int, array>  $chunk
     * @param  array<int, array{code: string, name: string}>  $chartOfAccounts
     * @param  array<string, string>  $accountMap
     * @param  array<int, array>  $ruleClassified  Transactions already classified by rules (context)
     */
    protected function classifyChunkWithAi(array $chunk, array $chartOfAccounts, array $accountMap, array $ruleClassified = []): array
    {
        // Get learned rules - these are the ONLY source for AI classification
        $learningRules = $this->getAllLearningRulesForAi();

        // If no rules exist, return all as unclassified (AI cannot guess without rules)
        if (empty($learningRules) && empty($ruleClassified)) {
            Log::info('No learning rules available - marking chunk as unclassified', [
                'chunk_size' => count($chunk),
            ]);

            return $this->markChunkAsUnclassified($chunk);
        }

        // Build rules list with pattern and account
        $rulesList = '';
        foreach ($learningRules as $i => $rule) {
            $pattern = substr($rule['pattern'], 0, 150);
            $rulesList .= sprintf("%d. [%s] %s\n", $i + 1, $rule['sap_code'], $pattern);
        }

        // Build context from transactions already classified (same batch)
        $contextSection = '';
        if (! empty($ruleClassified)) {
            $limitedContext = array_slice($ruleClassified, 0, 30);
            $contextList = '';
            foreach ($limitedContext as $t) {
                $memo = substr($t['raw_memo'] ?? $t['memo'] ?? '', 0, 80);
                $code = $t['sap_account_code'] ?? '';
                $name = $t['sap_account_name'] ?? '';
                $contextList .= sprintf("- [%s] %s → %s\n", $code, $memo, $name);
            }

            $contextSection = <<<CONTEXT

TRANSACCIONES YA CLASIFICADAS EN ESTE LOTE (usa como referencia):
{$contextList}
IMPORTANTE: Si una transacción tiene estructura SIMILAR (mismo prefijo, mismo beneficiario), asigna la MISMA cuenta.
CONTEXT;
        }

        // Build transactions list
        $transList = '';
        foreach ($chunk as $t) {
            $memo = substr($t['memo'], 0, 150);
            $type = ($t['debit_amount'] ?? 0) > 0 ? 'CARGO' : 'ABONO';
            $transList .= sprintf("%d. [%s] %s\n", $t['sequence'], $type, $memo);
        }

        $prompt = <<<PROMPT
Eres un clasificador de transacciones bancarias. Tu ÚNICA fuente de información son las REGLAS APRENDIDAS.

REGLAS APRENDIDAS (formato: [CUENTA_SAP] PATRON):
{$rulesList}
{$contextSection}

TRANSACCIONES A CLASIFICAR:
{$transList}

INSTRUCCIONES ESTRICTAS:
1. SOLO asigna cuentas que aparezcan en las REGLAS APRENDIDAS o en las TRANSACCIONES YA CLASIFICADAS
2. Compara el memo con los patrones - busca palabras clave similares
3. Ignora números de rastreo, referencias, horas y fechas
4. Si el memo es SIMILAR a un patrón conocido, asigna esa cuenta
5. Si NO hay coincidencia clara con ninguna regla, devuelve sap:null
6. Confianza: 90+ muy similar, 70-89 parcialmente similar, 0 si no hay match

RESPONDE SOLO JSON (sin explicaciones ni texto adicional):
[{"seq":1,"sap":"1010-000-000","conf":90},{"seq":2,"sap":null,"conf":0}]
PROMPT;

        // Log the prompt for debugging
        Log::info('AI Pattern Matching Request', [
            'transactions_count' => count($chunk),
            'rules_count' => count($learningRules),
            'rule_context_count' => count($ruleClassified),
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
                $suggestedCode = $aiResult['sap_code'] ?? null;
                $confidence = $aiResult['confidence'] ?? 0;

                if ($suggestedCode && $confidence > 0) {
                    // AI classified - get account name from accountMap or from learning rules
                    $accountName = $accountMap[$suggestedCode] ?? $this->getAccountNameFromRules($suggestedCode);

                    $classified[] = array_merge($transaction, [
                        'sap_account_code' => $suggestedCode,
                        'sap_account_name' => $accountName,
                        'confidence' => $confidence,
                        'source' => 'ai',
                    ]);
                } else {
                    // AI couldn't classify - mark as unclassified
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

    /**
     * Extract a clean pattern from a bank memo using AI.
     * Removes noise (tracking numbers, dates, generic words) and keeps only identifying keywords.
     *
     * @return array{keywords: string, actor: string|null, rfc: string|null, tipo: string}
     */
    public function extractCleanPattern(string $memo, ?float $debitAmount = null, ?float $creditAmount = null): array
    {
        $movementType = ($debitAmount ?? 0) > 0 ? 'CARGO (Egreso)' : 'ABONO (Ingreso)';

        $prompt = <<<PROMPT
ROL: Eres un Ingeniero de Datos experto en limpieza de textos bancarios (SPEI México). Tu objetivo es extraer únicamente los datos constantes para crear una REGLA DE CLASIFICACIÓN.

LISTA DE "RUIDO" A IGNORAR:
- RASTREO, REFERENCIA, REF, HORA, FOLIO, CIE
- "DATO NO VERIFICADO POR ESTA INSTITUCION"
- "A LA CTA CLABE", "DE LA CTA CLABE"
- "RFC ND" (Si es ND, ignóralo)
- Números largos (más de 6 dígitos) que no sean RFCs
- Palabras genéricas solas: TRANSFERENCIA, PAGO, SPEI, CONCEPTO

LÓGICA DE EXTRACCIÓN:
1. TIPO: Este movimiento es {$movementType}
2. ACTOR: Busca el nombre del beneficiario/ordenante
   - Si dice "ENVIADO A [BANCO] A [NOMBRE]", el actor es [NOMBRE]
   - Si dice "RECIBIDO DE [BANCO] DE [NOMBRE]", el actor es [NOMBRE]
3. RFC: Si hay RFC válido (3-4 letras + 6 números + 3 homoclave), extráelo
4. LIMPIEZA: Elimina "SA DE CV", "SAPI DE CV", "SC", "S DE RL"
5. KEYWORDS: Extrae palabras clave identificadoras (nombres comerciales, conceptos específicos)

EJEMPLOS:
Input: "RASTREO 1E04F968 SPEI RECIBIDO DE 40012-BBVA DE UBR PAGOS SA DE CV RFC UPA1808228K9 CONCEPTO UBER EATS"
Output: {"keywords": "UBR PAGOS, UBER EATS", "actor": "UBR PAGOS", "rfc": "UPA1808228K9", "tipo": "ABONO"}

Input: "PAGO NOMINA QUINCENA 15 ENERO 2025 FOLIO 123456"
Output: {"keywords": "NOMINA, QUINCENA", "actor": null, "rfc": null, "tipo": "CARGO"}

Input: "COMISION POR MANEJO DE CUENTA IVA INCLUIDO"
Output: {"keywords": "COMISION, MANEJO DE CUENTA", "actor": null, "rfc": null, "tipo": "CARGO"}

TEXTO A PROCESAR:
"{$memo}"

RESPONDE SOLO JSON (sin explicaciones):
{"keywords": "PALABRA1, PALABRA2", "actor": "nombre o null", "rfc": "RFC o null", "tipo": "CARGO/ABONO"}
PROMPT;

        try {
            /** @var GeminiClient $gemini */
            $gemini = app('gemini');
            $result = $gemini->generativeModel('gemini-2.0-flash')->generateContent($prompt);
            $responseText = trim($result->text());

            // Save for debugging
            file_put_contents(storage_path('logs/ai_pattern_extraction.txt'), "MEMO:\n{$memo}\n\nRESPONSE:\n{$responseText}\n\n", FILE_APPEND);

            // Clean JSON response
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

            $extracted = json_decode($responseText, true);

            if (json_last_error() === JSON_ERROR_NONE && isset($extracted['keywords'])) {
                Log::info('Pattern extracted with AI', [
                    'memo' => substr($memo, 0, 100),
                    'keywords' => $extracted['keywords'],
                    'actor' => $extracted['actor'] ?? null,
                    'rfc' => $extracted['rfc'] ?? null,
                ]);

                return [
                    'keywords' => strtoupper($extracted['keywords'] ?? ''),
                    'actor' => $extracted['actor'] ?? null,
                    'rfc' => $extracted['rfc'] ?? null,
                    'tipo' => $extracted['tipo'] ?? 'DESCONOCIDO',
                ];
            }
        } catch (\Exception $e) {
            Log::error('Failed to extract pattern with AI', [
                'memo' => substr($memo, 0, 100),
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback: return uppercase memo truncated
        return [
            'keywords' => strtoupper(substr($memo, 0, 100)),
            'actor' => null,
            'rfc' => null,
            'tipo' => ($debitAmount ?? 0) > 0 ? 'CARGO' : 'ABONO',
        ];
    }
}
