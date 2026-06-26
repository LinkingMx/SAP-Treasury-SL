<?php

namespace App\Services\Acquirer;

use App\Enums\SettlementUploadStatus;
use App\Models\ExternalSettlement;
use App\Models\PaymentOrder;
use App\Models\SettlementUpload;
use App\Services\Ai\SettlementLayoutAnalyzer;
use App\Services\GcorePaymentsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Orchestrates reconciliation of an acquirer settlement upload: read the file,
 * pull the Parrot payments from the gCore API, match in memory, and persist the
 * reconciliation links. The payments are never mirrored locally.
 */
final class AcquirerReconciliationService
{
    public function __construct(
        private SettlementLayoutAnalyzer $analyzer,
        private GcorePaymentsService $gcore,
        private AcquirerMatcher $matcher,
    ) {}

    /**
     * Full pipeline for the queued job: read the stored file with AI column
     * detection, then reconcile.
     */
    public function processUpload(SettlementUpload $upload): UploadResult
    {
        $file = $this->resolveStoredFile($upload);
        $analysis = $this->analyzer->analyze($file);
        $rows = $this->analyzer->parseRows($file, $analysis['parse_config']);

        return $this->reconcile($upload, $rows);
    }

    /**
     * Core reconciliation: persist settlement rows, fetch API payments, match,
     * and persist payment_orders. Idempotent — clears prior rows for the upload.
     * IO is limited to the gCore API (fake it in tests) and the database.
     *
     * @param  array<int, array<string, mixed>>  $rows  rows from SettlementLayoutAnalyzer::parseRows()
     */
    public function reconcile(SettlementUpload $upload, array $rows): UploadResult
    {
        return DB::transaction(function () use ($upload, $rows) {
            $upload->paymentOrders()->delete();
            $upload->externalSettlements()->delete();

            $acquirer = $upload->acquirer;
            $branch = $upload->branch;

            $settlementIds = $this->insertSettlements($upload, $rows);

            $payments = $this->fetchPayments($upload);

            $excluded = PaymentOrder::query()
                ->where('branch_id', $branch->id)
                ->pluck('parrot_payment_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $results = $this->matcher->match($rows, $payments, $acquirer->matchRule(), $excluded);

            $matched = 0;
            foreach ($results as $result) {
                if (! $result->matched()) {
                    continue;
                }

                $row = $rows[$result->rowIndex];

                PaymentOrder::create([
                    'upload_id' => $upload->id,
                    'external_settlement_id' => $settlementIds[$result->rowIndex],
                    'acquirer_id' => $acquirer->id,
                    'branch_id' => $branch->id,
                    'parrot_payment_id' => $result->parrotPaymentId,
                    'order_reference' => $result->orderReference,
                    'payment_total' => $result->paymentTotal,
                    'external_reference' => $row['authorization'] ?? null,
                    'match_method' => $result->method,
                    'match_diff' => $result->diff,
                    'matched_at' => now(),
                ]);

                ExternalSettlement::whereKey($settlementIds[$result->rowIndex])
                    ->update(['match_status' => ExternalSettlement::MATCH_MATCHED]);

                $matched++;
            }

            $total = count($rows);
            $upload->update([
                'status' => SettlementUploadStatus::Done,
                'total_rows' => $total,
                'matched_rows' => $matched,
                'unmatched_rows' => $total - $matched,
            ]);

            return new UploadResult($total, $matched);
        });
    }

    /**
     * Insert external_settlements rows; returns map of rowIndex => settlement id.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, int>
     */
    private function insertSettlements(SettlementUpload $upload, array $rows): array
    {
        $ids = [];

        foreach ($rows as $index => $row) {
            $settlement = ExternalSettlement::create([
                'upload_id' => $upload->id,
                'acquirer_id' => $upload->acquirer_id,
                'branch_id' => $upload->branch_id,
                'transaction_date' => $row['transaction_date'],
                'transaction_time' => $row['transaction_time'] ?? null,
                'amount' => $row['amount'],
                'card_type' => $row['card_type'] ?? null,
                'card_brand' => $row['card_brand'] ?? null,
                'authorization' => $row['authorization'] ?? null,
                'reference' => $row['reference'] ?? null,
                'terminal' => $row['terminal'] ?? null,
                'operation_type' => $row['operation_type'] ?? null,
                'status' => $row['status'] ?? null,
                'match_status' => ExternalSettlement::MATCH_UNMATCHED,
                'raw' => $row['raw'] ?? null,
            ]);

            $ids[$index] = $settlement->id;
        }

        return $ids;
    }

    /**
     * Pull all Parrot CHARGED payments for the upload's branch and period.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchPayments(SettlementUpload $upload): array
    {
        $branch = $upload->branch;

        if (empty($branch->payment_branch)) {
            throw new \RuntimeException("La sucursal «{$branch->name}» no tiene configurado el campo «Sucursal en API de Pagos» (payment_branch).");
        }

        $result = $this->gcore->allParrotOrderPayments(
            $branch->payment_branch,
            $upload->period_start->format('Y-m-d'),
            $upload->period_end->format('Y-m-d'),
            ['status' => 'CHARGED'],
        );

        if (! $result['success']) {
            throw new \RuntimeException('Error al consultar pagos en gCore: '.($result['error'] ?? 'desconocido'));
        }

        return $result['data'];
    }

    /**
     * Rebuild an UploadedFile handle from the upload's stored file.
     */
    private function resolveStoredFile(SettlementUpload $upload): UploadedFile
    {
        if (empty($upload->stored_path)) {
            throw new \RuntimeException('La carga no tiene un archivo asociado.');
        }

        $absolutePath = Storage::disk('local')->path($upload->stored_path);

        if (! is_file($absolutePath)) {
            throw new \RuntimeException("No se encontró el archivo de la carga en {$absolutePath}.");
        }

        return new UploadedFile($absolutePath, $upload->original_name, null, null, true);
    }
}
