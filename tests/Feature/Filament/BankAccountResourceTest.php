<?php

use App\Filament\Resources\BankAccounts\Pages\CreateBankAccount;
use App\Filament\Resources\BankAccounts\Pages\EditBankAccount;
use App\Filament\Resources\BankAccounts\Pages\ListBankAccounts;
use App\Models\BankAccount;
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
    Livewire::test(ListBankAccounts::class)
        ->assertOk();
});

it('can list bank accounts', function () {
    $bankAccounts = BankAccount::factory()->count(3)->create();

    Livewire::test(ListBankAccounts::class)
        ->assertCanSeeTableRecords($bankAccounts);
});

it('can render the create page', function () {
    Livewire::test(CreateBankAccount::class)
        ->assertOk();
});

it('can create a bank account', function () {
    $branch = Branch::factory()->create();

    Livewire::test(CreateBankAccount::class)
        ->fillForm([
            'branch_id' => $branch->id,
            'name' => 'Cuenta Operativa',
            'account' => '0123-4567-8901234567',
        ])
        ->call('create')
        ->assertNotified()
        ->assertRedirect();

    assertDatabaseHas(BankAccount::class, [
        'branch_id' => $branch->id,
        'name' => 'Cuenta Operativa',
        'account' => '0123-4567-8901234567',
    ]);
});

it('validates required fields on create', function (array $data, array $errors) {
    $branch = Branch::factory()->create();

    Livewire::test(CreateBankAccount::class)
        ->fillForm([
            'branch_id' => $branch->id,
            'name' => 'Test Account',
            'account' => '1234-5678-9012345678',
            ...$data,
        ])
        ->call('create')
        ->assertHasFormErrors($errors)
        ->assertNotNotified()
        ->assertNoRedirect();
})->with([
    '`branch_id` is required' => [['branch_id' => null], ['branch_id' => 'required']],
    '`name` is required' => [['name' => null], ['name' => 'required']],
    '`account` is required' => [['account' => null], ['account' => 'required']],
]);

it('validates unique account per branch', function () {
    $branch = Branch::factory()->create();
    $existingAccount = BankAccount::factory()->create([
        'branch_id' => $branch->id,
        'account' => '1111-2222-3333333333',
    ]);

    Livewire::test(CreateBankAccount::class)
        ->fillForm([
            'branch_id' => $branch->id,
            'name' => 'Nueva Cuenta',
            'account' => '1111-2222-3333333333',
        ])
        ->call('create')
        ->assertHasFormErrors(['account' => 'unique'])
        ->assertNotNotified()
        ->assertNoRedirect();
});

it('allows same account number in different branches', function () {
    $branch1 = Branch::factory()->create();
    $branch2 = Branch::factory()->create();

    BankAccount::factory()->create([
        'branch_id' => $branch1->id,
        'account' => '1111-2222-3333333333',
    ]);

    Livewire::test(CreateBankAccount::class)
        ->fillForm([
            'branch_id' => $branch2->id,
            'name' => 'Cuenta en otra sucursal',
            'account' => '1111-2222-3333333333',
        ])
        ->call('create')
        ->assertNotified()
        ->assertRedirect();

    assertDatabaseHas(BankAccount::class, [
        'branch_id' => $branch2->id,
        'account' => '1111-2222-3333333333',
    ]);
});

it('can render the edit page', function () {
    $bankAccount = BankAccount::factory()->create();

    Livewire::test(EditBankAccount::class, [
        'record' => $bankAccount->id,
    ])
        ->assertOk()
        ->assertSchemaStateSet([
            'branch_id' => $bankAccount->branch_id,
            'name' => $bankAccount->name,
            'account' => $bankAccount->account,
        ]);
});

it('can update a bank account', function () {
    $bankAccount = BankAccount::factory()->create();
    $newBranch = Branch::factory()->create();

    Livewire::test(EditBankAccount::class, [
        'record' => $bankAccount->id,
    ])
        ->fillForm([
            'branch_id' => $newBranch->id,
            'name' => 'Cuenta Actualizada',
            'account' => '9999-8888-7777777777',
        ])
        ->call('save')
        ->assertNotified()
        ->assertRedirect();

    assertDatabaseHas(BankAccount::class, [
        'id' => $bankAccount->id,
        'branch_id' => $newBranch->id,
        'name' => 'Cuenta Actualizada',
        'account' => '9999-8888-7777777777',
    ]);
});

it('can delete a bank account', function () {
    $bankAccount = BankAccount::factory()->create();

    Livewire::test(EditBankAccount::class, [
        'record' => $bankAccount->id,
    ])
        ->callAction(DeleteAction::class)
        ->assertNotified()
        ->assertRedirect();

    assertDatabaseMissing(BankAccount::class, [
        'id' => $bankAccount->id,
    ]);
});

it('can search bank accounts by name', function () {
    $bankAccounts = BankAccount::factory()->count(5)->create();
    $searchAccount = $bankAccounts->first();

    Livewire::test(ListBankAccounts::class)
        ->searchTable($searchAccount->name)
        ->assertCanSeeTableRecords([$searchAccount]);
});

it('can search bank accounts by account number', function () {
    $bankAccounts = BankAccount::factory()->count(5)->create();
    $searchAccount = $bankAccounts->first();

    Livewire::test(ListBankAccounts::class)
        ->searchTable($searchAccount->account)
        ->assertCanSeeTableRecords([$searchAccount]);
});

it('can sort bank accounts by name', function () {
    $branch = Branch::factory()->create();

    $accountA = BankAccount::factory()->create(['branch_id' => $branch->id, 'name' => 'A Cuenta']);
    $accountB = BankAccount::factory()->create(['branch_id' => $branch->id, 'name' => 'B Cuenta']);
    $accountC = BankAccount::factory()->create(['branch_id' => $branch->id, 'name' => 'C Cuenta']);

    Livewire::test(ListBankAccounts::class)
        ->sortTable('name')
        ->assertCanSeeTableRecords([$accountA, $accountB, $accountC], inOrder: true)
        ->sortTable('name', 'desc')
        ->assertCanSeeTableRecords([$accountC, $accountB, $accountA], inOrder: true);
});
