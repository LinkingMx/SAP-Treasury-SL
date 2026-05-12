<?php

use App\Models\VendorPaymentInvoice;
use App\Services\SapServiceLayer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config()->set('services.sap_service_layer.base_url', 'https://sap-test:50000/b1s/v1');
    config()->set('services.sap_service_layer.username', 'manager');
    config()->set('services.sap_service_layer.password', 'secret');
});

function loginThenFakePaymentResponse(string $docNum = '100'): void
{
    Http::fake([
        '*/Login' => Http::response(['SessionId' => 'fake-session-id'], 200),
        '*/VendorPayments' => Http::response(['DocNum' => $docNum], 201),
    ]);
}

it('sends Comments joined by ", " in row order', function () {
    loginThenFakePaymentResponse();

    $sap = new SapServiceLayer;
    expect($sap->login('TEST_DB'))->toBeTrue();

    $invoices = collect([
        VendorPaymentInvoice::factory()->make([
            'card_code' => 'P00073',
            'line_num' => 0,
            'proveedor_ref' => 'IN3456',
        ]),
        VendorPaymentInvoice::factory()->make([
            'card_code' => 'P00073',
            'line_num' => 1,
            'proveedor_ref' => 'IN346556',
        ]),
        VendorPaymentInvoice::factory()->make([
            'card_code' => 'P00073',
            'line_num' => 2,
            'proveedor_ref' => 'IN5646',
        ]),
    ])->all();

    $result = $sap->createVendorPayment($invoices);

    expect($result['success'])->toBeTrue();

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/VendorPayments')) {
            return false;
        }

        return $request['Comments'] === 'IN3456, IN346556, IN5646';
    });
});

it('truncates Comments to 254 chars and logs a warning', function () {
    loginThenFakePaymentResponse();
    Log::spy();

    $sap = new SapServiceLayer;
    $sap->login('TEST_DB');

    $invoices = [];
    for ($i = 0; $i < 30; $i++) {
        $invoices[] = VendorPaymentInvoice::factory()->make([
            'card_code' => 'P00073',
            'line_num' => $i,
            'proveedor_ref' => 'REFERENCIA-LARGA-NUMERO-'.$i,
        ]);
    }

    $sap->createVendorPayment($invoices);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/VendorPayments')) {
            return false;
        }

        return mb_strlen($request['Comments']) === 254;
    });

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message) => str_contains($message, 'truncated'))
        ->once();
});

it('omits Comments when no proveedor_ref present', function () {
    loginThenFakePaymentResponse();

    $sap = new SapServiceLayer;
    $sap->login('TEST_DB');

    $invoices = [
        VendorPaymentInvoice::factory()->make([
            'card_code' => 'P00073',
            'line_num' => 0,
            'proveedor_ref' => null,
        ]),
    ];

    $sap->createVendorPayment($invoices);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/VendorPayments')) {
            return false;
        }

        return ! array_key_exists('Comments', $request->data());
    });
});
