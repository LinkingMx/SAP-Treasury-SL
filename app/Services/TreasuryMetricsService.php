<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\Branch;
use App\Models\CustomerPaymentBatch;
use App\Models\Transaction;
use App\Models\VendorPaymentBatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Computes treasury KPIs for the manager dashboard.
 *
 * Reconciliation/operations metrics come from the app's own tables (always
 * available). Cash, payables and receivables metrics query the per-branch SAP
 * Business One company databases over the `sap_sqlsrv` connection and degrade
 * gracefully when that connection is unreachable (e.g. local dev).
 *
 * @phpstan-type DateRange array{from: string, to: string}
 */
class TreasuryMetricsService
{
    /**
     * Reconciliation & operations health — sourced entirely from local tables.
     *
     * @param  Collection<int, int>  $branchIds
     * @return array<string, mixed>
     */
    public function reconciliationHealth(Collection $branchIds, string $from, string $to): array
    {
        $inBranches = fn ($query) => $query->whereIn('branch_id', $branchIds);
        $inRange = fn ($query) => $query->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59']);

        $txTotal = Transaction::query()
            ->whereHas('batch', fn ($q) => $inBranches($q))
            ->tap($inRange)
            ->count();

        $txPosted = Transaction::query()
            ->whereHas('batch', fn ($q) => $inBranches($q))
            ->whereNotNull('sap_number')
            ->tap($inRange)
            ->count();

        $vendorFailed = VendorPaymentBatch::query()->tap($inBranches)->tap($inRange)->where('status', 'failed')->count();
        $customerFailed = CustomerPaymentBatch::query()->tap($inBranches)->tap($inRange)->where('status', 'failed')->count();
        $batchFailed = Batch::query()->tap($inBranches)->tap($inRange)->where('status', 'failed')->count();

        $pendingBatches = Batch::query()->tap($inBranches)->tap($inRange)->whereIn('status', ['pending', 'processing'])->count();

        $avgProcessingHours = Batch::query()
            ->tap($inBranches)
            ->tap($inRange)
            ->whereNotNull('processed_at')
            ->get(['created_at', 'processed_at'])
            ->avg(fn ($b) => $b->processed_at->floatDiffInHours($b->created_at));

        return [
            'cards' => [
                'auto_post_rate' => $txTotal > 0 ? round($txPosted / $txTotal * 100, 1) : null,
                'posted_tx' => $txPosted,
                'pending_tx' => $txTotal - $txPosted,
                'failed_batches' => $vendorFailed + $customerFailed + $batchFailed,
                'pending_batches' => $pendingBatches,
                'avg_processing_hours' => $avgProcessingHours !== null ? round((float) $avgProcessingHours, 1) : null,
            ],
            'batch_status' => $this->batchStatusBreakdown($branchIds, $from, $to),
            'recent_failures' => $this->recentFailures($branchIds),
        ];
    }

    /**
     * @param  Collection<int, int>  $branchIds
     * @return array<int, array{source: string, status: string, count: int}>
     */
    private function batchStatusBreakdown(Collection $branchIds, string $from, string $to): array
    {
        $range = [$from.' 00:00:00', $to.' 23:59:59'];

        $rows = collect();

        foreach ([
            'Pagos a SAP' => VendorPaymentBatch::class,
            'Cobros' => CustomerPaymentBatch::class,
            'Extractos (lotes)' => Batch::class,
        ] as $label => $model) {
            $model::query()
                ->whereIn('branch_id', $branchIds)
                ->whereBetween('created_at', $range)
                ->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->get()
                ->each(fn ($r) => $rows->push([
                    'source' => $label,
                    'status' => $r->status,
                    'count' => (int) $r->count,
                ]));
        }

        return $rows->all();
    }

    /**
     * @param  Collection<int, int>  $branchIds
     * @return array<int, array<string, mixed>>
     */
    private function recentFailures(Collection $branchIds): array
    {
        $vendor = VendorPaymentBatch::query()
            ->whereIn('branch_id', $branchIds)
            ->where('status', 'failed')
            ->latest()
            ->limit(5)
            ->get(['id', 'branch_id', 'filename', 'created_at'])
            ->map(fn ($b) => [
                'type' => 'Pago a SAP',
                'id' => $b->id,
                'branch' => $b->branch?->name,
                'filename' => $b->filename,
                'created_at' => $b->created_at?->toIso8601String(),
            ]);

        $statement = \App\Models\BankStatement::query()
            ->whereIn('branch_id', $branchIds)
            ->where('status', 'failed')
            ->latest()
            ->limit(5)
            ->get(['id', 'branch_id', 'original_filename', 'created_at'])
            ->map(fn ($s) => [
                'type' => 'Extracto',
                'id' => $s->id,
                'branch' => $s->branch?->name,
                'filename' => $s->original_filename,
                'created_at' => $s->created_at?->toIso8601String(),
            ]);

        return $vendor->concat($statement)
            ->sortByDesc('created_at')
            ->take(8)
            ->values()
            ->all();
    }

    /**
     * Cash position by branch from SAP B1 GL (JDT1) — bank/cash accounts.
     *
     * Requires the `sap_sqlsrv` SQL Server connection. Returns
     * ['available' => false] when SAP is unreachable so the dashboard can show
     * a graceful placeholder.
     *
     * @param  Collection<int, Branch>  $branches
     * @return array<string, mixed>
     */
    public function cashPosition(Collection $branches): array
    {
        // The GL (OACT) lives at SAP company-database level, and several app
        // branches may map to the same company DB — so query each unique
        // sap_database once to avoid double counting.
        $companies = $branches
            ->filter(fn ($b) => filled($b->sap_database))
            ->groupBy('sap_database');

        $byCompany = [];
        $failed = [];
        $cajaTotal = 0.0;
        $bancoTotal = 0.0;

        foreach ($companies as $companyDb => $companyBranches) {
            try {
                config(['database.connections.sap_sqlsrv.database' => $companyDb]);
                DB::purge('sap_sqlsrv');

                $rows = DB::connection('sap_sqlsrv')
                    ->table('OACT')
                    ->where('Postable', 'Y')
                    ->where(function ($q) {
                        $q->where('AcctCode', 'like', '1010-%')
                            ->orWhere('AcctCode', 'like', '1020-%');
                    })
                    ->selectRaw("CASE WHEN AcctCode LIKE '1010-%' THEN 'caja' ELSE 'banco' END as kind, SUM(CurrTotal) as total")
                    ->groupBy(DB::raw("CASE WHEN AcctCode LIKE '1010-%' THEN 'caja' ELSE 'banco' END"))
                    ->pluck('total', 'kind');

                $caja = (float) ($rows['caja'] ?? 0);
                $banco = (float) ($rows['banco'] ?? 0);

                $byCompany[] = [
                    'company_db' => $companyDb,
                    'branches' => $companyBranches->pluck('name')->values()->all(),
                    'caja' => round($caja, 2),
                    'banco' => round($banco, 2),
                    'total' => round($caja + $banco, 2),
                ];
                $cajaTotal += $caja;
                $bancoTotal += $banco;
            } catch (\Throwable $e) {
                $failed[] = [
                    'company_db' => $companyDb,
                    'branches' => $companyBranches->pluck('name')->values()->all(),
                    'reason' => $e->getMessage(),
                ];
            }
        }

        usort($byCompany, fn ($a, $b) => $b['total'] <=> $a['total']);

        return [
            'available' => true,
            'currency' => 'MXN',
            'consolidated' => [
                'caja' => round($cajaTotal, 2),
                'banco' => round($bancoTotal, 2),
                'total' => round($cajaTotal + $bancoTotal, 2),
            ],
            'by_company' => $byCompany,
            'failed_branches' => $failed,
        ];
    }

    /**
     * Aging of open A/P invoices (OPCH) bucketed against today's date.
     *
     * @param  Collection<int, Branch>  $branches
     * @return array<string, mixed>
     */
    public function payablesAging(Collection $branches): array
    {
        return $this->openInvoiceAging($branches, 'OPCH', 'payables');
    }

    /**
     * Aging of open A/R invoices (OINV) bucketed against today's date.
     *
     * @param  Collection<int, Branch>  $branches
     * @return array<string, mixed>
     */
    public function receivablesAging(Collection $branches): array
    {
        return $this->openInvoiceAging($branches, 'OINV', 'receivables');
    }

    /**
     * Shared aging engine for OPCH (A/P) and OINV (A/R). Buckets open balance
     * (DocTotal − PaidToDate) by days past DocDueDate vs today.
     *
     * @param  Collection<int, Branch>  $branches
     * @return array<string, mixed>
     */
    private function openInvoiceAging(Collection $branches, string $table, string $kind): array
    {
        $companies = $branches
            ->filter(fn ($b) => filled($b->sap_database))
            ->groupBy('sap_database');

        $today = now()->toDateString();
        $buckets = [
            'not_due' => 0.0,
            '0_30' => 0.0,
            '31_60' => 0.0,
            '61_90' => 0.0,
            '90_plus' => 0.0,
        ];
        $byCompany = [];
        $failed = [];
        $totalOpen = 0.0;
        $totalInvoices = 0;

        foreach ($companies as $companyDb => $companyBranches) {
            try {
                config(['database.connections.sap_sqlsrv.database' => $companyDb]);
                DB::purge('sap_sqlsrv');

                // `$today` is a server-formatted ISO date — safe to inline. Wrapped
                // in a subquery so SELECT/GROUP BY reference the same expression.
                $sql = <<<SQL
                    SELECT bucket, SUM(open_amount) AS open_amount, SUM(doc_count) AS doc_count
                    FROM (
                        SELECT
                            CASE
                                WHEN DocDueDate >= CAST('{$today}' AS DATE) THEN 'not_due'
                                WHEN DATEDIFF(day, DocDueDate, CAST('{$today}' AS DATE)) BETWEEN 0 AND 30 THEN '0_30'
                                WHEN DATEDIFF(day, DocDueDate, CAST('{$today}' AS DATE)) BETWEEN 31 AND 60 THEN '31_60'
                                WHEN DATEDIFF(day, DocDueDate, CAST('{$today}' AS DATE)) BETWEEN 61 AND 90 THEN '61_90'
                                ELSE '90_plus'
                            END AS bucket,
                            (DocTotal - ISNULL(PaidToDate, 0)) AS open_amount,
                            1 AS doc_count
                        FROM {$table}
                        WHERE DocStatus = 'O' AND CANCELED = 'N'
                    ) t
                    GROUP BY bucket
                    SQL;

                $rows = collect(DB::connection('sap_sqlsrv')->select($sql));

                $companyBuckets = array_fill_keys(array_keys($buckets), 0.0);
                $companyOpen = 0.0;
                $companyCount = 0;
                foreach ($rows as $r) {
                    $amount = (float) $r->open_amount;
                    $companyBuckets[$r->bucket] = round($amount, 2);
                    $buckets[$r->bucket] += $amount;
                    $companyOpen += $amount;
                    $companyCount += (int) $r->doc_count;
                }

                $byCompany[] = [
                    'company_db' => $companyDb,
                    'branches' => $companyBranches->pluck('name')->values()->all(),
                    'buckets' => $companyBuckets,
                    'open_total' => round($companyOpen, 2),
                    'doc_count' => $companyCount,
                ];
                $totalOpen += $companyOpen;
                $totalInvoices += $companyCount;
            } catch (\Throwable $e) {
                $failed[] = [
                    'company_db' => $companyDb,
                    'branches' => $companyBranches->pluck('name')->values()->all(),
                    'reason' => $e->getMessage(),
                ];
            }
        }

        usort($byCompany, fn ($a, $b) => $b['open_total'] <=> $a['open_total']);

        $overdue = $buckets['0_30'] + $buckets['31_60'] + $buckets['61_90'] + $buckets['90_plus'];

        return [
            'available' => true,
            'kind' => $kind,
            'currency' => 'MXN',
            'as_of' => $today,
            'consolidated' => [
                'open_total' => round($totalOpen, 2),
                'overdue_total' => round($overdue, 2),
                'doc_count' => $totalInvoices,
                'buckets' => array_map(fn ($v) => round($v, 2), $buckets),
            ],
            'by_company' => $byCompany,
            'failed_branches' => $failed,
        ];
    }

    /**
     * @return array{available: false, reason: string}
     */
    private function unavailable(): array
    {
        return [
            'available' => false,
            'reason' => 'Conexión a SAP no disponible en este entorno.',
        ];
    }
}
