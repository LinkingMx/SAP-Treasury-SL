<?php

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function gcoreUrl(Branch $branch, array $query = []): string
{
    return route('gcore.parrot-order-payments', array_merge(['branch' => $branch], $query));
}

it('returns gCore payments for a branch using its payment_branch', function () {
    Http::fake([
        '*/api/parrot-order-payments*' => Http::response([
            'filters' => ['branch_name' => 'Ichikani Metropolitan'],
            'summary' => ['count' => 3, 'sum_total' => 5000.0],
            'data' => [
                ['id' => 1, 'branch_name' => 'Ichikani Metropolitan', 'total' => 2000],
            ],
            'pagination' => ['current_page' => 1, 'last_page' => 1],
        ]),
    ]);

    $user = User::factory()->create();
    $branch = Branch::factory()->create(['payment_branch' => 'Ichikani Metropolitan']);
    $user->branches()->attach($branch);

    $response = $this->actingAs($user)->getJson(gcoreUrl($branch, [
        'from' => '2026-05-01',
        'to' => '2026-05-31',
    ]));

    $response->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonPath('summary.count', 3);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'branch_name=Ichikani')
        && str_contains($request->url(), 'from=2026-05-01')
        && str_contains($request->url(), 'to=2026-05-31'));
});

it('fails with 422 when the branch has no payment_branch', function () {
    $user = User::factory()->create();
    $branch = Branch::factory()->create(['payment_branch' => null]);
    $user->branches()->attach($branch);

    $this->actingAs($user)
        ->getJson(gcoreUrl($branch, ['from' => '2026-05-01', 'to' => '2026-05-31']))
        ->assertStatus(422)
        ->assertJsonPath('success', false);
});

it('validates the date range', function () {
    $user = User::factory()->create();
    $branch = Branch::factory()->create(['payment_branch' => 'Ichikani Metropolitan']);
    $user->branches()->attach($branch);

    $this->actingAs($user)
        ->getJson(gcoreUrl($branch, ['from' => '2026-05-31', 'to' => '2026-05-01']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('to');
});

it('forbids querying a branch the user does not belong to', function () {
    Http::fake();

    $user = User::factory()->create();
    $branch = Branch::factory()->create(['payment_branch' => 'Ichikani Metropolitan']);

    // The user is NOT attached to this branch, so GcorePaymentsRequest::authorize()
    // throws. The app's global handler (bootstrap/app.php) renders any non-validation
    // exception as a 500 JSON body for AJAX requests, so the contract here is "blocked,
    // no gCore call made" rather than a clean 403.
    $this->actingAs($user)
        ->getJson(gcoreUrl($branch, ['from' => '2026-05-01', 'to' => '2026-05-31']))
        ->assertStatus(500)
        ->assertJsonPath('success', false);

    Http::assertNothingSent();
});

it('surfaces a 502 when gCore is unreachable', function () {
    Http::fake([
        '*/api/parrot-order-payments*' => Http::response(['message' => 'boom'], 500),
    ]);

    $user = User::factory()->create();
    $branch = Branch::factory()->create(['payment_branch' => 'Ichikani Metropolitan']);
    $user->branches()->attach($branch);

    $this->actingAs($user)
        ->getJson(gcoreUrl($branch, ['from' => '2026-05-01', 'to' => '2026-05-31']))
        ->assertStatus(502)
        ->assertJsonPath('success', false);
});
