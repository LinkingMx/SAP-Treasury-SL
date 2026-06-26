<?php

use App\Models\Acquirer;
use App\Models\Branch;
use App\Models\ExternalSettlement;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function fakeParrot(array $rows): void
{
    Http::fake([
        '*/api/parrot-order-payments*' => Http::response([
            'data' => $rows,
            'pagination' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 100, 'total' => count($rows)],
        ]),
    ]);
}

function p(string $type, float $amount, float $tip, string $status = 'CHARGED'): array
{
    return [
        'id' => fake()->unique()->numberBetween(1, 1_000_000),
        'payment_type_name' => $type,
        'amount' => $amount,
        'tip' => $tip,
        'total' => $amount + $tip,
        'status' => $status,
        'created_at_pos' => '2026-05-15T12:00:00-06:00',
    ];
}

function ownerOf(Branch $branch): User
{
    $user = User::factory()->create();
    $user->branches()->attach($branch);

    return $user;
}

function dataUrl(int $branchId, string $from = '2026-05-01', string $to = '2026-05-31'): string
{
    return route('parrot-payments.data', ['branch_id' => $branchId, 'date_from' => $from, 'date_to' => $to]);
}

it('aggregates CHARGED payments by type and excludes VOIDED', function () {
    fakeParrot([
        p('CREDITO', 90, 10),
        p('CREDITO', 50, 0),
        p('DEBITO', 200, 0),
        p('EFECTIVO', 30, 0),
        p('CREDITO', 999, 0, 'VOIDED'),
    ]);

    $branch = Branch::factory()->create(['payment_branch' => 'Ichikani Metropolitan']);
    $user = ownerOf($branch);

    $response = $this->actingAs($user)->getJson(dataUrl($branch->id))->assertSuccessful();

    $json = $response->json();

    expect($json['success'])->toBeTrue()
        ->and($json['totals']['count'])->toBe(4)
        ->and((float) $json['totals']['sum_total'])->toBe(380.0)
        ->and((float) $json['totals']['sum_tip'])->toBe(10.0);

    // Sorted by sum_total desc: DEBITO (200), CREDITO (150), EFECTIVO (30)
    expect($json['by_payment_type'])->toHaveCount(3)
        ->and($json['by_payment_type'][0]['payment_type_name'])->toBe('DEBITO')
        ->and((float) $json['by_payment_type'][0]['sum_total'])->toBe(200.0);

    $credito = collect($json['by_payment_type'])->firstWhere('payment_type_name', 'CREDITO');
    expect($credito['count'])->toBe(2)
        ->and((float) $credito['sum_total'])->toBe(150.0);

    // Restaurant business-day window: 2026-05-01..2026-05-31 → 05:00 to next-day 05:00.
    expect($json['window'])->toBe(['from' => '2026-05-01T05:00:00', 'to' => '2026-06-01T05:00:00']);
    Http::assertSent(function ($request) {
        $url = urldecode($request->url());

        return str_contains($url, 'from=2026-05-01T05:00:00')
            && str_contains($url, 'to=2026-06-01T05:00:00');
    });
});

it('returns 422 when the branch has no payment_branch', function () {
    fakeParrot([]);

    $branch = Branch::factory()->create(['payment_branch' => null]);
    $user = ownerOf($branch);

    $this->actingAs($user)
        ->getJson(dataUrl($branch->id))
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    Http::assertNothingSent();
});

it('validates the date range', function () {
    $branch = Branch::factory()->create(['payment_branch' => 'X']);
    $user = ownerOf($branch);

    $this->actingAs($user)
        ->getJson(dataUrl($branch->id, '2026-05-31', '2026-05-01'))
        ->assertStatus(422)
        ->assertJsonValidationErrors('date_to');
});

it('blocks a branch the user does not own', function () {
    Http::fake();

    $branch = Branch::factory()->create(['payment_branch' => 'X']);
    $user = User::factory()->create(); // not attached

    // Authorization denial surfaces as 500 via the app's JSON exception handler.
    $this->actingAs($user)
        ->getJson(dataUrl($branch->id))
        ->assertStatus(500)
        ->assertJsonPath('success', false);

    Http::assertNothingSent();
});

it('surfaces a 502 when gCore is unreachable', function () {
    Http::fake(['*/api/parrot-order-payments*' => Http::response(['message' => 'boom'], 500)]);

    $branch = Branch::factory()->create(['payment_branch' => 'X']);
    $user = ownerOf($branch);

    $this->actingAs($user)
        ->getJson(dataUrl($branch->id))
        ->assertStatus(502)
        ->assertJsonPath('success', false);
});

it('annotates payment-type cards with conciliation when settlements are loaded', function () {
    fakeParrot([p('Rappi', 100, 0)]);

    $acquirer = Acquirer::factory()->delivery('Rappi')->create(['code' => 'RAPPI', 'name' => 'Rappi']);
    $branch = Branch::factory()->create(['payment_branch' => 'Ryoshi Polanco']);
    $user = ownerOf($branch);

    ExternalSettlement::factory()->create([
        'acquirer_id' => $acquirer->id,
        'branch_id' => $branch->id,
        'transaction_date' => '2026-05-15',
        'transaction_time' => '12:00:00',
        'amount' => 100.00,
        'status' => 'pending_review',
    ]);

    $json = $this->actingAs($user)->getJson(dataUrl($branch->id))->assertSuccessful()->json();

    $rappi = collect($json['by_payment_type'])->firstWhere('payment_type_name', 'Rappi');
    expect($rappi['has_settlements'])->toBeTrue()
        ->and($rappi['matched_count'])->toBe(1)
        ->and((float) $rappi['reconciled_pct'])->toBe(100.0)
        ->and($json['reconciliation']['RAPPI']['matched'])->toBe(1);
});

it('renders the page for an authenticated user', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('parrot-payments'))
        ->assertOk();
});

it('builds a restaurant business-day window (05:00 to next-day 05:00)', function () {
    expect(App\Services\GcorePaymentsService::businessDayWindow('2026-05-01', '2026-05-31'))
        ->toBe(['2026-05-01T05:00:00', '2026-06-01T05:00:00']);

    expect(App\Services\GcorePaymentsService::businessDayWindow('2026-05-15', '2026-05-15'))
        ->toBe(['2026-05-15T05:00:00', '2026-05-16T05:00:00']);
});
