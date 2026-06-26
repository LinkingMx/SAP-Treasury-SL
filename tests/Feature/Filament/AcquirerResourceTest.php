<?php

use App\Filament\Resources\Acquirers\Pages\CreateAcquirer;
use App\Filament\Resources\Acquirers\Pages\EditAcquirer;
use App\Filament\Resources\Acquirers\Pages\ListAcquirers;
use App\Models\Acquirer;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('can render the list page', function () {
    Livewire::test(ListAcquirers::class)->assertOk();
});

it('can list acquirers', function () {
    $acquirers = Acquirer::factory()->count(3)->create();

    Livewire::test(ListAcquirers::class)->assertCanSeeTableRecords($acquirers);
});

it('can render the create page', function () {
    Livewire::test(CreateAcquirer::class)->assertOk();
});

it('creates an acquirer and uppercases the code', function () {
    Livewire::test(CreateAcquirer::class)
        ->fillForm([
            'code' => 'mifel',
            'name' => 'CC MIFEL',
            'kind' => 'BANK',
            'parrot_payment_type_names' => ['CREDITO', 'DEBITO', 'AMEX'],
            'amount_tolerance' => 0.10,
            'active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    assertDatabaseHas('acquirers', ['code' => 'MIFEL', 'name' => 'CC MIFEL', 'kind' => 'BANK']);
});

it('can render the edit page', function () {
    $acquirer = Acquirer::factory()->create();

    Livewire::test(EditAcquirer::class, ['record' => $acquirer->getRouteKey()])->assertOk();
});

it('can update an acquirer', function () {
    $acquirer = Acquirer::factory()->delivery('Rappi')->create();

    Livewire::test(EditAcquirer::class, ['record' => $acquirer->getRouteKey()])
        ->fillForm(['amount_tolerance' => 0.75])
        ->call('save')
        ->assertHasNoFormErrors();

    assertDatabaseHas('acquirers', ['id' => $acquirer->id, 'amount_tolerance' => 0.75]);
});
