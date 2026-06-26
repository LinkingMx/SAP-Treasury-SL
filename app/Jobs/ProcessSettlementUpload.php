<?php

namespace App\Jobs;

use App\Enums\SettlementUploadStatus;
use App\Models\SettlementUpload;
use App\Services\Acquirer\SettlementIngestService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessSettlementUpload implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    public function __construct(public int $uploadId) {}

    /**
     * Execute the job.
     */
    public function handle(SettlementIngestService $service): void
    {
        $lock = Cache::lock("settlement-upload-{$this->uploadId}", 300);

        if (! $lock->get()) {
            Log::warning('Settlement upload already processing, skipping', ['upload_id' => $this->uploadId]);

            return;
        }

        try {
            $upload = SettlementUpload::findOrFail($this->uploadId);
            $upload->update(['status' => SettlementUploadStatus::Parsing, 'error_log' => null]);

            $result = $service->ingestUpload($upload);

            Log::info('Settlement upload ingested', [
                'upload_id' => $this->uploadId,
                'total' => $result->total,
                'inserted' => $result->inserted,
                'duplicates' => $result->duplicates,
            ]);
        } catch (\Throwable $e) {
            SettlementUpload::whereKey($this->uploadId)->update([
                'status' => SettlementUploadStatus::Failed,
                'error_log' => $e->getMessage(),
            ]);

            Log::error('Settlement upload failed', [
                'upload_id' => $this->uploadId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            $lock->release();
        }
    }
}
