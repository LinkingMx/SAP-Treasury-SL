<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SapServiceLayer
{
    protected string $baseUrl;

    protected string $username;

    protected string $password;

    protected ?string $sessionId = null;

    public function __construct()
    {
        $this->baseUrl = config('services.sap_service_layer.base_url');
        $this->username = config('services.sap_service_layer.username');
        $this->password = config('services.sap_service_layer.password');
    }

    /**
     * Login to SAP Service Layer and get session cookie.
     *
     * @throws \Exception
     */
    public function login(string $companyDB): bool
    {
        Log::info('SAP Service Layer login attempt', [
            'companyDB' => $companyDB,
            'baseUrl' => $this->baseUrl,
        ]);

        try {
            $response = Http::withoutVerifying()
                ->withOptions(['verify' => false])
                ->post("{$this->baseUrl}/Login", [
                    'CompanyDB' => $companyDB,
                    'UserName' => $this->username,
                    'Password' => $this->password,
                ]);

            if ($response->successful()) {
                $cookies = $response->cookies();
                foreach ($cookies as $cookie) {
                    if ($cookie->getName() === 'B1SESSION') {
                        $this->sessionId = $cookie->getValue();

                        Log::info('SAP Login successful', [
                            'companyDB' => $companyDB,
                            'sessionId' => substr($this->sessionId, 0, 10).'...',
                        ]);

                        return true;
                    }
                }

                // Fallback: try to get session from response body
                $data = $response->json();
                if (isset($data['SessionId'])) {
                    $this->sessionId = $data['SessionId'];

                    Log::info('SAP Login successful (from body)', [
                        'companyDB' => $companyDB,
                        'sessionId' => substr($this->sessionId, 0, 10).'...',
                    ]);

                    return true;
                }

                Log::warning('SAP Login successful but no session found', [
                    'companyDB' => $companyDB,
                    'response' => $response->json(),
                ]);

                return false;
            }

            Log::error('SAP Login failed', [
                'companyDB' => $companyDB,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            throw new \Exception('SAP Login failed: '.$response->json('error.message.value', 'Unknown error'));
        } catch (ConnectionException $e) {
            Log::error('SAP Connection failed', [
                'companyDB' => $companyDB,
                'error' => $e->getMessage(),
            ]);
            throw new \Exception('Cannot connect to SAP Service Layer: '.$e->getMessage());
        }
    }

    /**
     * Logout from SAP Service Layer.
     */
    public function logout(): void
    {
        if ($this->sessionId) {
            try {
                Http::withoutVerifying()
                    ->withOptions(['verify' => false])
                    ->withCookies(['B1SESSION' => $this->sessionId], parse_url($this->baseUrl, PHP_URL_HOST))
                    ->post("{$this->baseUrl}/Logout");
            } catch (\Exception $e) {
                Log::warning('SAP Logout failed', ['error' => $e->getMessage()]);
            }
            $this->sessionId = null;
        }
    }

    /**
     * Create a Journal Entry in SAP.
     *
     * @return array{success: bool, jdt_num: int|null, error: string|null}
     */
    public function createJournalEntry(
        Transaction $transaction,
        string $bankAccountCode,
        string $ceco,
        ?int $bplId
    ): array {
        if (! $this->sessionId) {
            return [
                'success' => false,
                'jdt_num' => null,
                'error' => 'Not logged in to SAP Service Layer',
            ];
        }

        $dueDate = $transaction->due_date->format('Y-m-d\TH:i:s\Z');
        $debitAmount = (float) $transaction->debit_amount;
        $creditAmount = (float) $transaction->credit_amount;

        // Build journal entry lines
        $lines = [];

        // Line 1: Bank Account
        $line1 = [
            'AccountCode' => $bankAccountCode,
            'Debit' => $creditAmount,
            'Credit' => $debitAmount,
            'CostingCode' => $ceco,
        ];
        if ($bplId !== null && $bplId !== 0) {
            $line1['BPLID'] = $bplId;
        }
        $lines[] = $line1;

        // Line 2: Counterpart Account
        $line2 = [
            'AccountCode' => $transaction->counterpart_account,
            'Debit' => $debitAmount,
            'Credit' => $creditAmount,
            'CostingCode' => $ceco,
        ];
        if ($bplId !== null && $bplId !== 0) {
            $line2['BPLID'] = $bplId;
        }
        $lines[] = $line2;

        $payload = [
            'ReferenceDate' => $dueDate,
            'Memo' => $transaction->memo,
            'TaxDate' => $dueDate,
            'DueDate' => $dueDate,
            'JournalEntryLines' => $lines,
        ];

        try {
            $response = Http::withoutVerifying()
                ->withOptions(['verify' => false])
                ->withCookies(['B1SESSION' => $this->sessionId], parse_url($this->baseUrl, PHP_URL_HOST))
                ->post("{$this->baseUrl}/JournalEntries", $payload);

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'success' => true,
                    'jdt_num' => $data['JdtNum'] ?? null,
                    'error' => null,
                ];
            }

            $errorMessage = $response->json('error.message.value', 'Unknown SAP error');
            Log::error('SAP JournalEntry creation failed', [
                'transaction_id' => $transaction->id,
                'status' => $response->status(),
                'error' => $errorMessage,
                'payload' => $payload,
            ]);

            return [
                'success' => false,
                'jdt_num' => null,
                'error' => $errorMessage,
            ];

        } catch (ConnectionException $e) {
            Log::error('SAP Connection failed during JournalEntry creation', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'jdt_num' => null,
                'error' => 'Connection error: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Check if session is active.
     */
    public function isLoggedIn(): bool
    {
        return $this->sessionId !== null;
    }

    /**
     * Create Bank Pages in SAP (one request per row).
     *
     * BankPages endpoint format:
     * - AccountCode: string (SAP GL account)
     * - DueDate: string (ISO format with timestamp)
     * - DebitAmount: float
     * - CreditAmount: float
     * - DocNumberType: 'bpdt_DocNum'
     * - PaymentReference: string (description/memo)
     *
     * @param  array<int, array>  $rows  Bank page rows to create (with original_index key)
     * @return array{success: bool, created_count: int, failed_count: int, errors: array, results: array}
     */
    public function createBankPages(array $rows): array
    {
        if (! $this->sessionId) {
            return [
                'success' => false,
                'created_count' => 0,
                'failed_count' => count($rows),
                'errors' => ['Not logged in to SAP Service Layer'],
                'results' => [],
            ];
        }

        $createdCount = 0;
        $failedCount = 0;
        $errors = [];
        $results = [];

        Log::info('SAP BankPages batch start', [
            'rows_count' => count($rows),
        ]);

        foreach ($rows as $index => $row) {
            // Get the original index if provided, otherwise use current index
            $originalIndex = $row['_original_index'] ?? $index;

            // Remove internal tracking field before sending to SAP
            $sapRow = $row;
            unset($sapRow['_original_index']);

            try {
                $response = Http::withoutVerifying()
                    ->withOptions(['verify' => false])
                    ->timeout(30)
                    ->withCookies(['B1SESSION' => $this->sessionId], parse_url($this->baseUrl, PHP_URL_HOST))
                    ->post("{$this->baseUrl}/BankPages", $sapRow);

                if ($response->successful()) {
                    $data = $response->json();
                    $sequence = $data['Sequence'] ?? null;
                    $createdCount++;

                    $results[] = [
                        'index' => $originalIndex,
                        'success' => true,
                        'sap_sequence' => $sequence,
                        'error' => null,
                    ];

                    Log::debug('SAP BankPage created', [
                        'index' => $originalIndex,
                        'sequence' => $sequence,
                    ]);
                } else {
                    $errorMessage = $response->json('error.message.value', 'Unknown SAP error');
                    $errors[] = "Row {$originalIndex}: {$errorMessage}";
                    $failedCount++;

                    $results[] = [
                        'index' => $originalIndex,
                        'success' => false,
                        'sap_sequence' => null,
                        'error' => $errorMessage,
                    ];

                    Log::error('SAP BankPage creation failed', [
                        'index' => $originalIndex,
                        'status' => $response->status(),
                        'error' => $errorMessage,
                        'payload' => $sapRow,
                    ]);
                }
            } catch (ConnectionException $e) {
                $errors[] = "Row {$originalIndex}: Connection error - {$e->getMessage()}";
                $failedCount++;

                $results[] = [
                    'index' => $originalIndex,
                    'success' => false,
                    'sap_sequence' => null,
                    'error' => 'Connection error: '.$e->getMessage(),
                ];

                Log::error('SAP Connection failed during BankPage creation', [
                    'index' => $originalIndex,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('SAP BankPages batch complete', [
            'created' => $createdCount,
            'failed' => $failedCount,
        ]);

        return [
            'success' => $failedCount === 0,
            'created_count' => $createdCount,
            'failed_count' => $failedCount,
            'errors' => $errors,
            'results' => $results,
        ];
    }

    /**
     * Create a Bank Statement in SAP (legacy method using BankStatements endpoint).
     *
     * @deprecated Use createBankPages() instead
     *
     * @param  array<int, array>  $rows
     * @return array{success: bool, doc_entry: int|null, error: string|null}
     */
    public function createBankStatement(
        string $bankAccountKey,
        string $statementDate,
        string $statementNumber,
        array $rows
    ): array {
        if (! $this->sessionId) {
            return [
                'success' => false,
                'doc_entry' => null,
                'error' => 'Not logged in to SAP Service Layer',
            ];
        }

        $payload = [
            'BankAccountKey' => $bankAccountKey,
            'StatementDate' => $statementDate,
            'StatementNumber' => $statementNumber,
            'BankStatementRows' => $rows,
        ];

        Log::info('SAP BankStatement payload', [
            'bank_account_key' => $bankAccountKey,
            'statement_number' => $statementNumber,
            'rows_count' => count($rows),
        ]);

        try {
            $response = Http::withoutVerifying()
                ->withOptions(['verify' => false])
                ->timeout(120)
                ->withCookies(['B1SESSION' => $this->sessionId], parse_url($this->baseUrl, PHP_URL_HOST))
                ->post("{$this->baseUrl}/BankStatements", $payload);

            if ($response->successful()) {
                $data = $response->json();

                Log::info('SAP BankStatement created successfully', [
                    'doc_entry' => $data['DocEntry'] ?? null,
                    'statement_number' => $statementNumber,
                ]);

                return [
                    'success' => true,
                    'doc_entry' => $data['DocEntry'] ?? null,
                    'error' => null,
                ];
            }

            $errorMessage = $response->json('error.message.value', 'Unknown SAP error');
            Log::error('SAP BankStatement creation failed', [
                'statement_number' => $statementNumber,
                'status' => $response->status(),
                'error' => $errorMessage,
                'payload' => $payload,
            ]);

            return [
                'success' => false,
                'doc_entry' => null,
                'error' => $errorMessage,
            ];

        } catch (ConnectionException $e) {
            Log::error('SAP Connection failed during BankStatement creation', [
                'statement_number' => $statementNumber,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'doc_entry' => null,
                'error' => 'Connection error: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Get the Chart of Accounts from SAP.
     *
     * @return array<int, array{code: string, name: string}>
     */
    public function getChartOfAccounts(): array
    {
        if (! $this->sessionId) {
            Log::warning('Attempted to get Chart of Accounts without active session');

            return [];
        }

        try {
            $accounts = [];
            $skip = 0;
            $top = 500;

            do {
                $response = Http::withoutVerifying()
                    ->withOptions(['verify' => false])
                    ->timeout(120)
                    ->withCookies(['B1SESSION' => $this->sessionId], parse_url($this->baseUrl, PHP_URL_HOST))
                    ->get("{$this->baseUrl}/ChartOfAccounts", [
                        '$select' => 'Code,Name',
                        '$top' => $top,
                        '$skip' => $skip,
                    ]);

                if (! $response->successful()) {
                    Log::error('SAP ChartOfAccounts fetch failed', [
                        'status' => $response->status(),
                        'error' => $response->json('error.message.value', 'Unknown error'),
                    ]);
                    break;
                }

                $data = $response->json();
                $items = $data['value'] ?? [];

                foreach ($items as $item) {
                    $accounts[] = [
                        'code' => $item['Code'],
                        'name' => $item['Name'],
                    ];
                }

                $skip += $top;
            } while (count($items) === $top);

            Log::info('SAP ChartOfAccounts fetched', ['count' => count($accounts)]);

            return $accounts;

        } catch (ConnectionException $e) {
            Log::error('SAP Connection failed during ChartOfAccounts fetch', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Create a Vendor Payment in SAP.
     *
     * @param  array  $invoices  Array de VendorPaymentInvoice grouped by CardCode
     * @return array{success: bool, doc_num: int|null, error: string|null}
     */
    public function createVendorPayment(array $invoices): array
    {
        if (! $this->sessionId) {
            return [
                'success' => false,
                'doc_num' => null,
                'error' => 'Not logged in to SAP Service Layer',
            ];
        }

        if (empty($invoices)) {
            return [
                'success' => false,
                'doc_num' => null,
                'error' => 'No invoices provided',
            ];
        }

        // Get data from first invoice (all invoices in group should have same data)
        $firstInvoice = $invoices[0];
        $cardCode = $firstInvoice->card_code;
        $docDate = $firstInvoice->doc_date->format('Y-m-d');
        $transferDate = $firstInvoice->transfer_date->format('Y-m-d');
        $transferAccount = $firstInvoice->transfer_account;

        // Build PaymentInvoices array
        $paymentInvoices = [];
        $transferSum = 0;

        foreach ($invoices as $invoice) {
            $paymentInvoices[] = [
                'LineNum' => $invoice->line_num,
                'DocEntry' => $invoice->doc_entry,
                'SumApplied' => (float) $invoice->sum_applied,
                'InvoiceType' => $invoice->invoice_type,
            ];

            $transferSum += (float) $invoice->sum_applied;
        }

        // Build payload
        $payload = [
            'CardCode' => $cardCode,
            'DocDate' => $docDate,
            'TransferSum' => $transferSum,
            'TransferAccount' => $transferAccount,
            'TransferDate' => $transferDate,
            'PaymentInvoices' => $paymentInvoices,
        ];

        Log::info('SAP VendorPayment payload', [
            'card_code' => $cardCode,
            'transfer_sum' => $transferSum,
            'invoices_count' => count($paymentInvoices),
        ]);

        try {
            $response = Http::withoutVerifying()
                ->withOptions(['verify' => false])
                ->timeout(30)
                ->withCookies(['B1SESSION' => $this->sessionId], parse_url($this->baseUrl, PHP_URL_HOST))
                ->post("{$this->baseUrl}/VendorPayments", $payload);

            if ($response->successful()) {
                $data = $response->json();
                $docNum = $data['DocNum'] ?? null;

                Log::info('SAP VendorPayment created successfully', [
                    'card_code' => $cardCode,
                    'doc_num' => $docNum,
                ]);

                return [
                    'success' => true,
                    'doc_num' => $docNum,
                    'error' => null,
                ];
            }

            $errorMessage = $response->json('error.message.value', 'Unknown SAP error');
            Log::error('SAP VendorPayment creation failed', [
                'card_code' => $cardCode,
                'status' => $response->status(),
                'error' => $errorMessage,
                'payload' => $payload,
            ]);

            return [
                'success' => false,
                'doc_num' => null,
                'error' => $errorMessage,
            ];

        } catch (ConnectionException $e) {
            Log::error('SAP Connection failed during VendorPayment creation', [
                'card_code' => $cardCode,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'doc_num' => null,
                'error' => 'Connection error: '.$e->getMessage(),
            ];
        }
    }
}
