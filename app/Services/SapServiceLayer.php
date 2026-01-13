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
     * @return array{success: bool, doc_entry: int|null, error: string|null}
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
                'doc_entry' => null,
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
                    'doc_entry' => $data['DocEntry'] ?? null,
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
                'doc_entry' => null,
                'error' => $errorMessage,
            ];

        } catch (ConnectionException $e) {
            Log::error('SAP Connection failed during JournalEntry creation', [
                'transaction_id' => $transaction->id,
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
     * Check if session is active.
     */
    public function isLoggedIn(): bool
    {
        return $this->sessionId !== null;
    }
}
