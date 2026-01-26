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

                        return true;
                    }
                }

                // Fallback: try to get session from response body
                $data = $response->json();
                if (isset($data['SessionId'])) {
                    $this->sessionId = $data['SessionId'];

                    return true;
                }

                Log::warning('SAP Login successful but no session found', [
                    'response' => $response->json(),
                ]);

                return false;
            }

            Log::error('SAP Login failed', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            throw new \Exception('SAP Login failed: '.$response->json('error.message.value', 'Unknown error'));
        } catch (ConnectionException $e) {
            Log::error('SAP Connection failed', ['error' => $e->getMessage()]);
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
}
