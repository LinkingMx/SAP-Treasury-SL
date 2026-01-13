<?php

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Branch;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('can render the list page', function () {
    Livewire::test(ListUsers::class)
        ->assertOk();
});

it('can list users', function () {
    $users = User::factory()->count(3)->create();

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords($users);
});

it('can render the create page', function () {
    Livewire::test(CreateUser::class)
        ->assertOk();
});

it('can create a user', function () {
    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])
        ->call('create')
        ->assertNotified()
        ->assertRedirect();

    assertDatabaseHas(User::class, [
        'name' => 'Test User',
        'email' => 'test@example.com',
    ]);
});

it('validates required fields on create', function (array $data, array $errors) {
    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            ...$data,
        ])
        ->call('create')
        ->assertHasFormErrors($errors)
        ->assertNotNotified()
        ->assertNoRedirect();
})->with([
    '`name` is required' => [['name' => null], ['name' => 'required']],
    '`email` is required' => [['email' => null], ['email' => 'required']],
    '`password` is required on create' => [['password' => null], ['password' => 'required']],
]);

it('validates email is unique', function () {
    $existingUser = User::factory()->create(['email' => 'existing@example.com']);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'New User',
            'email' => 'existing@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])
        ->call('create')
        ->assertHasFormErrors(['email' => 'unique'])
        ->assertNotNotified()
        ->assertNoRedirect();
});

it('validates password confirmation', function () {
    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'differentpassword',
        ])
        ->call('create')
        ->assertHasFormErrors(['password' => 'confirmed'])
        ->assertNotNotified()
        ->assertNoRedirect();
});

it('can render the edit page', function () {
    $user = User::factory()->create();

    Livewire::test(EditUser::class, [
        'record' => $user->id,
    ])
        ->assertOk()
        ->assertSchemaStateSet([
            'name' => $user->name,
            'email' => $user->email,
        ]);
});

it('can update a user without changing password', function () {
    $user = User::factory()->create();
    $originalPassword = $user->password;

    Livewire::test(EditUser::class, [
        'record' => $user->id,
    ])
        ->fillForm([
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
        ])
        ->call('save')
        ->assertNotified()
        ->assertRedirect();

    $user->refresh();
    expect($user->name)->toBe('Updated Name');
    expect($user->email)->toBe('updated@example.com');
    expect($user->password)->toBe($originalPassword);
});

it('can update a user with new password', function () {
    $user = User::factory()->create();
    $originalPassword = $user->password;

    Livewire::test(EditUser::class, [
        'record' => $user->id,
    ])
        ->fillForm([
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])
        ->call('save')
        ->assertNotified()
        ->assertRedirect();

    $user->refresh();
    expect($user->password)->not->toBe($originalPassword);
});

it('can delete a user', function () {
    $user = User::factory()->create();

    Livewire::test(EditUser::class, [
        'record' => $user->id,
    ])
        ->callAction(DeleteAction::class)
        ->assertNotified()
        ->assertRedirect();

    assertDatabaseMissing(User::class, [
        'id' => $user->id,
    ]);
});

it('can search users by name', function () {
    $users = User::factory()->count(5)->create();
    $searchUser = $users->first();

    Livewire::test(ListUsers::class)
        ->searchTable($searchUser->name)
        ->assertCanSeeTableRecords([$searchUser]);
});

it('can search users by email', function () {
    $users = User::factory()->count(5)->create();
    $searchUser = $users->first();

    Livewire::test(ListUsers::class)
        ->searchTable($searchUser->email)
        ->assertCanSeeTableRecords([$searchUser]);
});

it('can assign roles to a user', function () {
    $role = Role::create(['name' => 'admin']);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'roles' => [$role->id],
        ])
        ->call('create')
        ->assertNotified()
        ->assertRedirect();

    $user = User::where('email', 'admin@example.com')->first();
    expect($user->hasRole('admin'))->toBeTrue();
});

it('can assign branches to a user', function () {
    $branches = Branch::factory()->count(2)->create();

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Branch User',
            'email' => 'branch@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'branches' => $branches->pluck('id')->toArray(),
        ])
        ->call('create')
        ->assertNotified()
        ->assertRedirect();

    $user = User::where('email', 'branch@example.com')->first();
    expect($user->branches)->toHaveCount(2);
    expect($user->branches->pluck('id')->toArray())->toEqual($branches->pluck('id')->toArray());
});

it('can update user branches', function () {
    $user = User::factory()->create();
    $branches = Branch::factory()->count(3)->create();

    // Initially assign 2 branches
    $user->branches()->attach($branches->take(2)->pluck('id'));

    Livewire::test(EditUser::class, [
        'record' => $user->id,
    ])
        ->fillForm([
            'branches' => [$branches->last()->id],
        ])
        ->call('save')
        ->assertNotified()
        ->assertRedirect();

    $user->refresh();
    expect($user->branches)->toHaveCount(1);
    expect($user->branches->first()->id)->toBe($branches->last()->id);
});
