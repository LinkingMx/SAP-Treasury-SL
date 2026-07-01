<?php

use App\Models\Transaction;
use App\Services\SapServiceLayer;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.sap_service_layer.base_url', 'https://sap-test:50000/b1s/v1');
});

function loggedInSap(): SapServiceLayer
{
    Http::fake([
        '*/Login' => Http::response(['SessionId' => 'fake-session'], 200),
        '*/JournalEntries' => Http::response(['JdtNum' => 555], 201),
    ]);

    $sap = new SapServiceLayer;
    expect($sap->login('TEST_DB'))->toBeTrue();

    return $sap;
}

function journalLines(): array
{
    $sent = null;
    Http::assertSent(function ($request) use (&$sent) {
        if (str_contains($request->url(), 'JournalEntries')) {
            $sent = $request->data()['JournalEntryLines'];

            return true;
        }

        return false;
    });

    return $sent;
}

it('omits CostingCode from journal lines when the branch has no CECO (null)', function () {
    $sap = loggedInSap();
    $tx = Transaction::factory()->make(['batch_id' => 1, 'counterpart_account' => '2001-0001', 'debit_amount' => 100, 'credit_amount' => 0]);

    $result = $sap->createJournalEntry($tx, '1020-0001', null, 12);

    expect($result['success'])->toBeTrue();
    expect(collect(journalLines())->contains(fn (array $line) => array_key_exists('CostingCode', $line)))->toBeFalse();
});

it('includes CostingCode on every journal line when the branch has a CECO', function () {
    $sap = loggedInSap();
    $tx = Transaction::factory()->make(['batch_id' => 1, 'counterpart_account' => '2001-0001', 'debit_amount' => 100, 'credit_amount' => 0]);

    $sap->createJournalEntry($tx, '1020-0001', 'CC-100', 12);

    expect(collect(journalLines())->every(fn (array $line) => ($line['CostingCode'] ?? null) === 'CC-100'))->toBeTrue();
});
