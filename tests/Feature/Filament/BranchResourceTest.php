<?php

use App\Filament\Resources\Branches\Pages\CreateBranch;
use App\Filament\Resources\Branches\Pages\EditBranch;
use App\Filament\Resources\Branches\Pages\ListBranches;
use App\Models\Branch;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('can render the list page', function () {
    Livewire::test(ListBranches::class)
        ->assertOk();
});

it('can list branches', function () {
    $branches = Branch::factory()->count(3)->create();

    Livewire::test(ListBranches::class)
        ->assertCanSeeTableRecords($branches);
});

it('can render the create page', function () {
    Livewire::test(CreateBranch::class)
        ->assertOk();
});

it('can create a branch', function () {
    Livewire::test(CreateBranch::class)
        ->fillForm([
            'name' => 'Sucursal Centro',
            'sap_database' => 'SBO_PRODUCCION',
            'sap_branch_id' => 1,
        ])
        ->call('create')
        ->assertNotified()
        ->assertRedirect();

    assertDatabaseHas(Branch::class, [
        'name' => 'Sucursal Centro',
        'sap_database' => 'SBO_PRODUCCION',
        'sap_branch_id' => 1,
    ]);
});

it('validates required fields on create', function (array $data, array $errors) {
    Livewire::test(CreateBranch::class)
        ->fillForm([
            'name' => 'Test Branch',
            'sap_database' => 'SBO_TEST',
            'sap_branch_id' => 1,
            ...$data,
        ])
        ->call('create')
        ->assertHasFormErrors($errors)
        ->assertNotNotified()
        ->assertNoRedirect();
})->with([
    '`name` is required' => [['name' => null], ['name' => 'required']],
    '`sap_database` is required' => [['sap_database' => null], ['sap_database' => 'required']],
    '`sap_branch_id` is required' => [['sap_branch_id' => null], ['sap_branch_id' => 'required']],
]);

it('can render the edit page', function () {
    $branch = Branch::factory()->create();

    Livewire::test(EditBranch::class, [
        'record' => $branch->id,
    ])
        ->assertOk()
        ->assertSchemaStateSet([
            'name' => $branch->name,
            'sap_database' => $branch->sap_database,
            'sap_branch_id' => $branch->sap_branch_id,
        ]);
});

it('can update a branch', function () {
    $branch = Branch::factory()->create();

    Livewire::test(EditBranch::class, [
        'record' => $branch->id,
    ])
        ->fillForm([
            'name' => 'Sucursal Actualizada',
            'sap_database' => 'SBO_NUEVO',
            'sap_branch_id' => 99,
        ])
        ->call('save')
        ->assertNotified()
        ->assertRedirect();

    assertDatabaseHas(Branch::class, [
        'id' => $branch->id,
        'name' => 'Sucursal Actualizada',
        'sap_database' => 'SBO_NUEVO',
        'sap_branch_id' => 99,
    ]);
});

it('can delete a branch', function () {
    $branch = Branch::factory()->create();

    Livewire::test(EditBranch::class, [
        'record' => $branch->id,
    ])
        ->callAction(DeleteAction::class)
        ->assertNotified()
        ->assertRedirect();

    assertDatabaseMissing(Branch::class, [
        'id' => $branch->id,
    ]);
});

it('can search branches by name', function () {
    $branches = Branch::factory()->count(5)->create();
    $searchBranch = $branches->first();

    Livewire::test(ListBranches::class)
        ->searchTable($searchBranch->name)
        ->assertCanSeeTableRecords([$searchBranch]);
});

it('can sort branches by name', function () {
    $branches = Branch::factory()->count(3)->create();

    Livewire::test(ListBranches::class)
        ->sortTable('name')
        ->assertCanSeeTableRecords($branches->sortBy('name'), inOrder: true)
        ->sortTable('name', 'desc')
        ->assertCanSeeTableRecords($branches->sortByDesc('name'), inOrder: true);
});
