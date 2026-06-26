<?php

use App\Models\Acquirer;
use App\Models\Branch;
use App\Models\ExternalSettlement;
use App\Models\SettlementUpload;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function ownerUserForBranch(Branch $branch): User
{
    $user = User::factory()->create();
    $user->branches()->attach($branch);

    return $user;
}

function settlementCsv(string $name = 'rappi.csv'): UploadedFile
{
    $content = "Fecha,Autorizacion,Monto\n10/05/2026,A1,100.00\n12/05/2026,A2,200.00\n";

    return UploadedFile::fake()->createWithContent($name, $content);
}

function mappingParseConfig(): string
{
    return json_encode([
        'columns' => [
            'transaction_date' => ['index' => 0, 'header' => 'Fecha', 'format' => 'DD/MM/YYYY'],
            'authorization' => ['index' => 1, 'header' => 'Autorizacion'],
            'amount' => ['index' => 2, 'header' => 'Monto'],
        ],
        'header_lines_count' => 1,
        'delimiter' => ',',
    ]);
}

it('reads headers and suggests a mapping', function () {
    $acquirer = Acquirer::factory()->delivery('Rappi')->create();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('settlements.headers'), [
            'acquirer_id' => $acquirer->id,
            'file' => settlementCsv(),
        ], ['Accept' => 'application/json'])
        ->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonPath('headers', ['Fecha', 'Autorizacion', 'Monto'])
        ->assertJsonPath('suggested_mapping.transaction_date', 0)
        ->assertJsonPath('suggested_mapping.amount', 2);
});

it('ingests rows with the manual mapping and dedups on re-upload', function () {
    Storage::fake('local');

    $acquirer = Acquirer::factory()->delivery('Rappi')->create();
    $branch = Branch::factory()->create();
    $user = ownerUserForBranch($branch);

    $payload = fn (): array => [
        'acquirer_id' => $acquirer->id,
        'branch_id' => $branch->id,
        'parse_config' => mappingParseConfig(),
        'file' => settlementCsv(),
    ];

    $this->actingAs($user)
        ->post(route('settlements.store'), $payload(), ['Accept' => 'application/json'])
        ->assertSuccessful()
        ->assertJsonPath('upload.inserted_rows', 2)
        ->assertJsonPath('upload.duplicate_rows', 0)
        ->assertJsonPath('upload.period_start', '2026-05-10')
        ->assertJsonPath('upload.period_end', '2026-05-12');

    expect(ExternalSettlement::count())->toBe(2);

    $this->actingAs($user)
        ->post(route('settlements.store'), $payload(), ['Accept' => 'application/json'])
        ->assertSuccessful()
        ->assertJsonPath('upload.inserted_rows', 0)
        ->assertJsonPath('upload.duplicate_rows', 2);

    expect(ExternalSettlement::count())->toBe(2)
        ->and(SettlementUpload::count())->toBe(2);
});

it('remembers the mapping on the acquirer when asked', function () {
    Storage::fake('local');

    $acquirer = Acquirer::factory()->delivery('Rappi')->create();
    $branch = Branch::factory()->create();
    $user = ownerUserForBranch($branch);

    $this->actingAs($user)
        ->post(route('settlements.store'), [
            'acquirer_id' => $acquirer->id,
            'branch_id' => $branch->id,
            'parse_config' => mappingParseConfig(),
            'remember' => '1',
            'file' => settlementCsv(),
        ], ['Accept' => 'application/json'])
        ->assertSuccessful();

    $map = $acquirer->fresh()->column_map;
    expect($map['columns']['transaction_date']['header'])->toBe('Fecha')
        ->and($map['columns']['amount']['header'])->toBe('Monto');
});

it('rejects a mapping missing the date or amount column', function () {
    Storage::fake('local');

    $acquirer = Acquirer::factory()->delivery('Rappi')->create();
    $branch = Branch::factory()->create();
    $user = ownerUserForBranch($branch);

    $this->actingAs($user)
        ->post(route('settlements.store'), [
            'acquirer_id' => $acquirer->id,
            'branch_id' => $branch->id,
            'parse_config' => json_encode(['columns' => ['amount' => ['index' => 2]]]),
            'file' => settlementCsv(),
        ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('parse_config.columns.transaction_date.index');
});

it('blocks uploading to a branch the user does not own', function () {
    $acquirer = Acquirer::factory()->delivery('Rappi')->create();
    $branch = Branch::factory()->create();
    $user = User::factory()->create(); // not attached

    $this->actingAs($user)
        ->post(route('settlements.store'), [
            'acquirer_id' => $acquirer->id,
            'branch_id' => $branch->id,
            'parse_config' => mappingParseConfig(),
            'file' => settlementCsv(),
        ], ['Accept' => 'application/json'])
        ->assertStatus(500)
        ->assertJsonPath('success', false);
});

it('shows upload status to an authorized user', function () {
    $branch = Branch::factory()->create();
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
