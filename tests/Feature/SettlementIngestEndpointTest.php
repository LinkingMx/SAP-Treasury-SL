<?php

use App\Models\Acquirer;
use App\Models\Branch;
use App\Models\ExternalSettlement;
use App\Models\SettlementUpload;
use App\Models\User;
use App\Services\Ai\SettlementLayoutAnalyzer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function ownerUserForBranch(Branch $branch): User
{
    $user = User::factory()->create();
    $user->branches()->attach($branch);

    return $user;
}

it('analyzes a file and returns the parse config', function () {
    $this->mock(SettlementLayoutAnalyzer::class, function ($mock) {
        $mock->shouldReceive('analyze')->once()->andReturn([
            'parse_config' => ['columns' => ['transaction_date' => ['index' => 0], 'amount' => ['index' => 1]]],
            'acquirer_guess' => 'MIFEL',
            'fingerprint' => 'fp123',
        ]);
    });

    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('settlements.analyze'), [
            'file' => UploadedFile::fake()->create('mifel.xlsx', 10),
        ])
        ->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonPath('acquirer_guess', 'MIFEL');
});

it('previews proposed matches without persisting', function () {
    $this->mock(SettlementLayoutAnalyzer::class, function ($mock) {
        $mock->shouldReceive('analyze')->andReturn([
            'parse_config' => ['columns' => []],
            'acquirer_guess' => 'MIFEL',
            'fingerprint' => 'fp',
        ]);
        $mock->shouldReceive('parseRows')->andReturn([
            ['transaction_date' => '2026-05-15', 'transaction_time' => null, 'amount' => 1000.00, 'authorization' => 'A1', 'raw' => []],
        ]);
    });

    Http::fake([
        '*/api/parrot-order-payments*' => Http::response([
            'data' => [
                ['id' => 1, 'payment_type_name' => 'CREDITO', 'total' => 1000.00, 'status' => 'CHARGED', 'created_at_pos' => '2026-05-15T12:00:00-06:00', 'order_reference' => 'R-1'],
            ],
            'pagination' => ['current_page' => 1, 'last_page' => 1],
        ]),
    ]);

    $acquirer = Acquirer::factory()->bank()->create();
    $branch = Branch::factory()->create(['payment_branch' => 'Ichikani Metropolitan']);
    $user = ownerUserForBranch($branch);

    $this->actingAs($user)
        ->postJson(route('settlements.preview'), [
            'acquirer_id' => $acquirer->id,
            'branch_id' => $branch->id,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'file' => UploadedFile::fake()->create('mifel.xlsx', 10),
        ])
        ->assertSuccessful()
        ->assertJsonPath('summary.total_rows', 1)
        ->assertJsonPath('summary.matched_rows', 1);

    expect(SettlementUpload::count())->toBe(0);
});

it('ingests rows on upload and dedups on re-upload', function () {
    Storage::fake('local');

    $this->mock(SettlementLayoutAnalyzer::class, function ($mock) {
        $mock->shouldReceive('analyze')->andReturn([
            'parse_config' => ['columns' => ['transaction_date' => ['index' => 0], 'amount' => ['index' => 1]]],
            'acquirer_guess' => 'RAPPI',
            'fingerprint' => 'fp',
        ]);
        $mock->shouldReceive('parseRows')->andReturn([
            ['transaction_date' => '2026-05-10', 'transaction_time' => null, 'amount' => 100.00, 'authorization' => 'A1', 'reference' => 'R1', 'raw' => []],
            ['transaction_date' => '2026-05-12', 'transaction_time' => null, 'amount' => 200.00, 'authorization' => 'A2', 'reference' => 'R2', 'raw' => []],
        ]);
    });

    $acquirer = Acquirer::factory()->delivery('Rappi')->create();
    $branch = Branch::factory()->create();
    $user = ownerUserForBranch($branch);

    $payload = fn () => [
        'acquirer_id' => $acquirer->id,
        'branch_id' => $branch->id,
        'file' => UploadedFile::fake()->create('rappi.xlsx', 10),
    ];

    // First upload inserts both rows.
    $this->actingAs($user)
        ->postJson(route('settlements.store'), $payload())
        ->assertSuccessful()
        ->assertJsonPath('upload.inserted_rows', 2)
        ->assertJsonPath('upload.duplicate_rows', 0)
        ->assertJsonPath('upload.period_start', '2026-05-10')
        ->assertJsonPath('upload.period_end', '2026-05-12');

    expect(ExternalSettlement::count())->toBe(2);

    // Re-uploading the same rows inserts nothing (all duplicates).
    $this->actingAs($user)
        ->postJson(route('settlements.store'), $payload())
        ->assertSuccessful()
        ->assertJsonPath('upload.inserted_rows', 0)
        ->assertJsonPath('upload.duplicate_rows', 2);

    expect(ExternalSettlement::count())->toBe(2)
        ->and(SettlementUpload::count())->toBe(2);
});

it('blocks uploading to a branch the user does not own', function () {
    $acquirer = Acquirer::factory()->bank()->create();
    $branch = Branch::factory()->create();
    $user = User::factory()->create(); // not attached

    $this->actingAs($user)
        ->postJson(route('settlements.store'), [
            'acquirer_id' => $acquirer->id,
            'branch_id' => $branch->id,
            'file' => UploadedFile::fake()->create('rappi.xlsx', 10),
        ])
        ->assertStatus(500)
        ->assertJsonPath('success', false);
});

it('blocks previewing a branch the user does not own', function () {
    $acquirer = Acquirer::factory()->bank()->create();
    $branch = Branch::factory()->create(['payment_branch' => 'X']);
    $user = User::factory()->create(); // not attached

    // Authorization failure surfaces as 500 via the app's JSON exception handler.
    $this->actingAs($user)
        ->postJson(route('settlements.preview'), [
            'acquirer_id' => $acquirer->id,
            'branch_id' => $branch->id,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'file' => UploadedFile::fake()->create('mifel.xlsx', 10),
        ])
        ->assertStatus(500)
        ->assertJsonPath('success', false);
});

it('shows upload status to an authorized user', function () {
    $branch = Branch::factory()->create(['payment_branch' => 'X']);
    $user = ownerUserForBranch($branch);
    $upload = SettlementUpload::factory()->create([
        'branch_id' => $branch->id,
        'inserted_rows' => 6,
        'total_rows' => 8,
    ]);

    $this->actingAs($user)
        ->getJson(route('settlements.show', $upload))
        ->assertSuccessful()
        ->assertJsonPath('upload.total_rows', 8)
        ->assertJsonPath('upload.inserted_rows', 6);
});
