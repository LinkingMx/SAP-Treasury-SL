<?php

use App\Jobs\ProcessBatchToSapJob;
use App\Models\Batch;

/**
 * A job that runs longer than the connection's retry_after gets re-dispatched
 * while still running. For ProcessBatchToSapJob that means posting the same
 * journal entries to SAP twice, so the ordering below must never invert.
 */
it('keeps the SAP batch job timeout below the queue retry_after', function () {
    $job = new ProcessBatchToSapJob(new Batch);
    $retryAfter = (int) config('queue.connections.database.retry_after');

    expect($retryAfter)->toBeGreaterThan($job->timeout);
});

it('gives the SAP batch job enough time for a large batch', function () {
    $job = new ProcessBatchToSapJob(new Batch);

    // ~0.3s per journal entry against SAP; a 1,164-row batch needs ~350s.
    expect($job->timeout)->toBeGreaterThanOrEqual(600);
});
