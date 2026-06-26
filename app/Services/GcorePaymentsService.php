<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GcorePaymentsService
{
    protected string $baseUrl;

    protected int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.gcore.base_url'), '/');
        $this->timeout = (int) config('services.gcore.timeout', 30);
    }

    /**
     * Fetch a single page of Parrot POS order payments from gCore.
     *
     * The branch is identified by the gCore `branch_name`, which in this app is
     * stored on `branches.payment_branch`.
     *
     * @param  array{branch_id?: int, payment_type?: string, status?: string, page?: int}  $filters
     * @return array{success: bool, status: int, filters: array|null, summary: array|null, data: array<int, array>, pagination: array|null, error: string|null}
     */
    public function parrotOrderPayments(string $branchName, string $from, string $to, array $filters = []): array
    {
        $query = array_merge([
            'branch_name' => $branchName,
            'from' => $from,
            'to' => $to,
        ], array_filter(
            $filters,
            static fn ($value): bool => $value !== null && $value !== '',
        ));

        try {
            $response = Http::timeout($this->timeout)
                ->acceptJson()
                ->get("{$this->baseUrl}/api/parrot-order-payments", $query);

            if (! $response->successful()) {
                Log::error('gCore parrot-order-payments fetch failed', [
                    'branch_name' => $branchName,
                    'status' => $response->status(),
                    'body' => $response->json() ?? $response->body(),
                ]);

                return [
                    'success' => false,
                    'status' => $response->status(),
                    'filters' => null,
                    'summary' => null,
                    'data' => [],
                    'pagination' => null,
                    'error' => $response->json('message', "gCore respondió con estado {$response->status()}."),
                ];
            }

            $payload = $response->json();

            return [
                'success' => true,
                'status' => $response->status(),
                'filters' => $payload['filters'] ?? null,
                'summary' => $payload['summary'] ?? null,
                'data' => $payload['data'] ?? [],
                'pagination' => $payload['pagination'] ?? null,
                'error' => null,
            ];
        } catch (ConnectionException $e) {
            Log::error('gCore connection failed during parrot-order-payments fetch', [
                'branch_name' => $branchName,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'status' => 0,
                'filters' => null,
                'summary' => null,
                'data' => [],
                'pagination' => null,
                'error' => 'No se pudo conectar al API de pagos de gCore: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Fetch every page of Parrot POS order payments, walking the pagination.
     *
     * @param  array{branch_id?: int, payment_type?: string, status?: string}  $filters
     * @return array{success: bool, summary: array|null, data: array<int, array>, pages_fetched: int, error: string|null}
     */
    public function allParrotOrderPayments(string $branchName, string $from, string $to, array $filters = []): array
    {
        $rows = [];
        $page = 1;
        $lastPage = 1;
        $summary = null;

        do {
            $result = $this->parrotOrderPayments($branchName, $from, $to, array_merge($filters, ['page' => $page]));

            if (! $result['success']) {
                return [
                    'success' => false,
                    'summary' => $summary,
                    'data' => $rows,
                    'pages_fetched' => $page - 1,
                    'error' => $result['error'],
                ];
            }

            $summary ??= $result['summary'];
            $rows = array_merge($rows, $result['data']);
            $lastPage = (int) ($result['pagination']['last_page'] ?? $page);
            $page++;
        } while ($page <= $lastPage);

        return [
            'success' => true,
            'summary' => $summary,
            'data' => $rows,
            'pages_fetched' => $lastPage,
            'error' => null,
        ];
    }
}
