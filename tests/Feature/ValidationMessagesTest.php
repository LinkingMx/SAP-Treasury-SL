<?php

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

/**
 * APP_LOCALE is "es" and so is the fallback, but the framework only ships "en".
 * Without lang/es/validation.php every untranslated rule leaked its raw key to
 * the user — which is how "validation.mimes" ended up on screen.
 */
it('translates framework validation messages to Spanish', function () {
    $validator = Validator::make(['edad' => 'x'], ['edad' => 'integer']);

    expect($validator->errors()->first('edad'))
        ->not->toContain('validation.')
        ->toContain('número entero');
});

it('explains in Spanish why a non-Excel file is rejected on customer payments', function () {
    $branch = Branch::factory()->create();
    $account = BankAccount::factory()->create(['branch_id' => $branch->id]);
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson(route('customer-payments.store'), [
            'branch_id' => $branch->id,
            'bank_account_id' => $account->id,
            'process_date' => '2026-08-06',
            'file' => UploadedFile::fake()->createWithContent('estado.csv', "a,b\n1,2\n"),
        ])
        ->assertStatus(422);

    $message = $response->json('errors.file.0');

    expect($message)->not->toContain('validation.')
        ->and($message)->toContain('Excel');
});
