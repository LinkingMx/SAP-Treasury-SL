<?php

use App\Models\PaymentOrder;
use App\Services\Acquirer\AcquirerMatcher;
use App\Services\Acquirer\MatchRule;

function payment(array $overrides = []): array
{
    return array_merge([
        'id' => 1,
        'payment_type_name' => 'CREDITO',
        'total' => 1000.00,
        'status' => 'CHARGED',
        'created_at_pos' => '2026-05-15T12:00:00-06:00',
        'order_reference' => '150526-P-0001',
    ], $overrides);
}

function settlementRow(array $overrides = []): array
{
    return array_merge([
        'transaction_date' => '2026-05-15',
        'transaction_time' => null,
        'amount' => 1000.00,
    ], $overrides);
}

$cardRule = fn (float $tol = 0.10, ?int $window = null) => new MatchRule(['CREDITO', 'DEBITO', 'AMEX'], $tol, $window);

it('matches a single exact payment', function () use ($cardRule) {
    $results = (new AcquirerMatcher)->match([settlementRow()], [payment()], $cardRule());

    expect($results)->toHaveCount(1)
        ->and($results[0]->matched())->toBeTrue()
        ->and($results[0]->parrotPaymentId)->toBe(1)
        ->and($results[0]->diff)->toBe(0.0)
        ->and($results[0]->method)->toBe(PaymentOrder::METHOD_AUTO_EXACT);
});

it('does not match across different dates, types, statuses or out of tolerance', function () use ($cardRule) {
    $payments = [
        payment(['id' => 1, 'created_at_pos' => '2026-05-16T12:00:00-06:00']), // wrong date
        payment(['id' => 2, 'payment_type_name' => 'EFECTIVO']),               // wrong type
        payment(['id' => 3, 'status' => 'VOIDED']),                            // voided
        payment(['id' => 4, 'total' => 1000.50]),                              // out of 0.10 tolerance
    ];

    $results = (new AcquirerMatcher)->match([settlementRow()], $payments, $cardRule());

    expect($results[0]->matched())->toBeFalse();
});

it('picks the closest candidate when several qualify', function () use ($cardRule) {
    $payments = [
        payment(['id' => 1, 'total' => 1000.08]),
        payment(['id' => 2, 'total' => 1000.02]), // closest
        payment(['id' => 3, 'total' => 999.95]),
    ];

    $results = (new AcquirerMatcher)->match([settlementRow()], $payments, $cardRule());

    expect($results[0]->parrotPaymentId)->toBe(2)
        ->and($results[0]->method)->toBe(PaymentOrder::METHOD_AUTO_FUZZY);
});

it('never matches the same payment twice (1-to-1)', function () use ($cardRule) {
    $rows = [settlementRow(), settlementRow()];

    $results = (new AcquirerMatcher)->match($rows, [payment()], $cardRule());

    expect($results[0]->matched())->toBeTrue()
        ->and($results[1]->matched())->toBeFalse();
});

it('excludes payments already matched elsewhere', function () use ($cardRule) {
    $results = (new AcquirerMatcher)->match([settlementRow()], [payment(['id' => 7])], $cardRule(), [7]);

    expect($results[0]->matched())->toBeFalse();
});

it('honours the time window when configured', function () use ($cardRule) {
    $payments = [payment(['created_at_pos' => '2026-05-15T12:40:00-06:00'])]; // 40 min away

    $row = settlementRow(['transaction_time' => '12:00:00']);

    $within = (new AcquirerMatcher)->match([$row], $payments, $cardRule(0.10, 1800)); // 30 min window
    expect($within[0]->matched())->toBeFalse();

    $wide = (new AcquirerMatcher)->match([$row], $payments, $cardRule(0.10, 3600)); // 60 min window
    expect($wide[0]->matched())->toBeTrue();
});
