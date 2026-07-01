<?php

use App\Enums\SettlementUploadStatus;
use App\Models\Acquirer;
use App\Models\Branch;
use App\Models\ExternalSettlement;
use App\Models\SettlementUpload;
use App\Models\User;
use App\Services\Acquirer\SettlementIngestService;

function makeIngestUpload(?Acquirer $acquirer = null, ?Branch $branch = null): SettlementUpload
{
    $acquirer ??= Acquirer::factory()->delivery('Rappi')->create();
    $branch ??= Branch::factory()->create();

    return SettlementUpload::factory()->create([
        'acquirer_id' => $acquirer->id,
        'branch_id' => $branch->id,
        'user_id' => User::factory()->create()->id,
        'status' => SettlementUploadStatus::Parsing,
    ]);
}

function ingestRow(string $date, float $amount, ?string $auth = null, ?string $ref = null, ?string $time = null): array
{
    return [
        'transaction_date' => $date,
        'transaction_time' => $time,
        'amount' => $amount,
        'authorization' => $auth,
        'reference' => $ref,
        'raw' => [],
    ];
}

it('inserts new rows and derives the period from the rows', function () {
    $upload = makeIngestUpload();

    $result = app(SettlementIngestService::class)->ingestRows($upload, [
        ingestRow('2026-05-10', 100, 'A1'),
        ingestRow('2026-05-20', 200, 'A2'),
        ingestRow('2026-05-05', 50, 'A3'),
    ]);

    expect($result->total)->toBe(3)
        ->and($result->inserted)->toBe(3)
        ->and($result->duplicates)->toBe(0);

    $upload->refresh();
    expect(ExternalSettlement::count())->toBe(3)
        ->and($upload->status)->toBe(SettlementUploadStatus::Done)
        ->and($upload->inserted_rows)->toBe(3)
        ->and($upload->period_start->format('Y-m-d'))->toBe('2026-05-05')
        ->and($upload->period_end->format('Y-m-d'))->toBe('2026-05-20');
});

it('bulk-inserts across chunk boundaries (more than 500 rows)', function () {
    $upload = makeIngestUpload();

    $rows = [];
    for ($i = 0; $i < 1200; $i++) {
        $rows[] = ingestRow('2026-05-'.str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT), 100 + $i, 'A'.$i, 'R'.$i);
    }

    $result = app(SettlementIngestService::class)->ingestRows($upload, $rows);

    expect($result->total)->toBe(1200)
        ->and($result->inserted)->toBe(1200)
        ->and(ExternalSettlement::count())->toBe(1200)
        ->and(ExternalSettlement::whereNotNull('created_at')->count())->toBe(1200);
});

it('skips rows already ingested for the same acquirer + branch', function () {
    $acquirer = Acquirer::factory()->delivery('Rappi')->create();
    $branch = Branch::factory()->create();
    $service = app(SettlementIngestService::class);

    $service->ingestRows(makeIngestUpload($acquirer, $branch), [
        ingestRow('2026-05-10', 100, 'A1'),
        ingestRow('2026-05-12', 200, 'A2'),
    ]);

    // Second file overlaps one row (A1) and adds one new (A3).
    $result = $service->ingestRows(makeIngestUpload($acquirer, $branch), [
        ingestRow('2026-05-10', 100, 'A1'),
        ingestRow('2026-05-15', 300, 'A3'),
    ]);

    expect($result->total)->toBe(2)
        ->and($result->inserted)->toBe(1)
        ->and($result->duplicates)->toBe(1)
        ->and(ExternalSettlement::count())->toBe(3);
});

it('dedups identical rows within the same file', function () {
    $upload = makeIngestUpload();

    $result = app(SettlementIngestService::class)->ingestRows($upload, [
        ingestRow('2026-05-10', 100, 'A1'),
        ingestRow('2026-05-10', 100, 'A1'),
        ingestRow('2026-05-10', 100, 'A1'),
    ]);

    expect($result->inserted)->toBe(1)
        ->and($result->duplicates)->toBe(2)
        ->and(ExternalSettlement::count())->toBe(1);
});

it('treats the same amount+date as distinct when authorization differs', function () {
    $upload = makeIngestUpload();

    $result = app(SettlementIngestService::class)->ingestRows($upload, [
        ingestRow('2026-05-10', 100, 'A1'),
        ingestRow('2026-05-10', 100, 'A2'),
    ]);

    expect($result->inserted)->toBe(2)
        ->and(ExternalSettlement::count())->toBe(2);
});

it('does not dedup across different branches', function () {
    $acquirer = Acquirer::factory()->delivery('Rappi')->create();
    $branchA = Branch::factory()->create();
    $branchB = Branch::factory()->create();
    $service = app(SettlementIngestService::class);

    $service->ingestRows(makeIngestUpload($acquirer, $branchA), [ingestRow('2026-05-10', 100, 'A1')]);
    $result = $service->ingestRows(makeIngestUpload($acquirer, $branchB), [ingestRow('2026-05-10', 100, 'A1')]);

    expect($result->inserted)->toBe(1)
        ->and(ExternalSettlement::count())->toBe(2);
});
