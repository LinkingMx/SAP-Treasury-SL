<?php

namespace App\Jobs;

use App\Models\Batch;
use App\Models\LearningRule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class LearnFromUserCorrections implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Create a new job instance.
     *
     * @param  array<int, array{sequence: int, memo: string, sap_account_code: string, sap_account_name: string|null, ai_suggested_account: string|null}>  $transactions
     */
    public function __construct(
        public Batch $batch,
        public array $transactions
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $learnedCount = 0;

        foreach ($this->transactions as $transaction) {
            // Skip if no AI suggestion or if user didn't change it
            if (empty($transaction['ai_suggested_account'])) {
                continue;
            }

            if ($transaction['ai_suggested_account'] === $transaction['sap_account_code']) {
                continue;
            }

            // User corrected the AI suggestion - learn from this
            $pattern = $this->extractPattern($transaction['memo']);

            if (empty($pattern)) {
                continue;
            }

            // Check if rule already exists
            $existingRule = LearningRule::where('pattern', $pattern)
                ->where('sap_account_code', $transaction['sap_account_code'])
                ->first();

            if ($existingRule) {
                // Increase confidence if same correction is made
                $existingRule->update([
                    'confidence_score' => min(100, $existingRule->confidence_score + 5),
                ]);
                Log::info('Learning rule confidence increased', [
                    'pattern' => $pattern,
                    'account' => $transaction['sap_account_code'],
                    'new_confidence' => $existingRule->confidence_score,
                ]);
            } else {
                // Create new rule
                LearningRule::create([
                    'pattern' => $pattern,
                    'match_type' => 'contains',
                    'sap_account_code' => $transaction['sap_account_code'],
                    'sap_account_name' => $transaction['sap_account_name'],
                    'confidence_score' => 100,
                    'source' => 'user_correction',
                ]);
                $learnedCount++;
                Log::info('New learning rule created from user correction', [
                    'pattern' => $pattern,
                    'account' => $transaction['sap_account_code'],
                    'original_suggestion' => $transaction['ai_suggested_account'],
                ]);
            }
        }

        if ($learnedCount > 0) {
            Log::info('Learning job completed', [
                'batch_id' => $this->batch->id,
                'new_rules_count' => $learnedCount,
            ]);
        }
    }

    /**
     * Extract a meaningful pattern from the memo.
     * Returns the full memo text (cleaned) to give AI maximum context.
     */
    protected function extractPattern(string $memo): ?string
    {
        // Clean up the memo - normalize whitespace
        $cleaned = trim(preg_replace('/\s+/', ' ', $memo));

        // Skip if too short
        if (strlen($cleaned) < 5) {
            return null;
        }

        // Return full memo in uppercase for consistent matching
        return strtoupper($cleaned);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Learning job failed', [
            'batch_id' => $this->batch->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
