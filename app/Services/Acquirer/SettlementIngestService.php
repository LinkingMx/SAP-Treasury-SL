<?php

namespace App\Services\Acquirer;

use App\Enums\SettlementUploadStatus;
use App\Models\ExternalSettlement;
use App\Models\SettlementUpload;
use App\Services\Ai\SettlementLayoutAnalyzer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Ingests acquirer settlement files into external_settlements, accumulating
 * across uploads and deduplicating per row. Does NOT match against gCore — that
 * is a separate reconciliation step.
 */
final class SettlementIngestService
{
    public function __construct(private SettlementLayoutAnalyzer $analyzer) {}

    /**
     * Read the stored file with AI column detection, then ingest. For the job.
     */
    public function ingestUpload(SettlementUpload $upload): IngestResult
    {
        return $this->ingestFromFile($upload, $this->resolveStoredFile($upload));
    }

    /**
     * Detect columns with AI, parse the rows, then dedup-insert them.
     */
    public function ingestFromFile(SettlementUpload $upload, UploadedFile $file): IngestResult
    {
        $analysis = $this->analyzer->analyze($file);
        $rows = $this->analyzer->parseRows($file, $analysis['parse_config']);

        return $this->ingestRows($upload, $rows);
    }

    /**
     * Dedup-insert parsed rows into external_settlements. Idempotent: rows whose
     * hash already exists for this acquirer+branch (or repeat within the file)
     * are skipped. Derives the upload period from the rows. No matching.
     *
     * @param  array<int, array<string, mixed>>  $rows  rows from SettlementLayoutAnalyzer::parseRows()
     */
    public function ingestRows(SettlementUpload $upload, array $rows): IngestResult
    {
        return DB::transaction(function () use ($upload, $rows) {
            $total = count($rows);

            $hashes = array_map(fn (array $row): string => ExternalSettlement::hashFor($row), $rows);

            $existing = ExternalSettlement::query()
                ->where('acquirer_id', $upload->acquirer_id)
                ->where('branch_id', $upload->branch_id)
                ->whereIn('row_hash', array_values(array_unique($hashes)))
                ->pluck('row_hash')
                ->flip();

            $seen = [];
            $inserted = 0;
            $dates = [];

            foreach ($rows as $index => $row) {
                $hash = $hashes[$index];
                $dates[] = $row['transaction_date'];

                if (isset($existing[$hash]) || isset($seen[$hash])) {
                    continue;
                }
                $seen[$hash] = true;

                ExternalSettlement::create([
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
                    'row_hash' => $hash,
                ]);

                $inserted++;
            }

            $periodStart = $dates === [] ? null : min($dates);
            $periodEnd = $dates === [] ? null : max($dates);

            $upload->update([
                'status' => SettlementUploadStatus::Done,
                'total_rows' => $total,
                'inserted_rows' => $inserted,
                'duplicate_rows' => $total - $inserted,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]);

            return new IngestResult($total, $inserted, $total - $inserted, $periodStart, $periodEnd);
        });
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
