<?php

use App\Models\User;

test('guests cannot download template', function () {
    $this->get(route('batches.template'))
        ->assertRedirect(route('login'));
});

test('authenticated users can download template', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('batches.template'));

    $response->assertOk();
    $response->assertDownload('plantilla_transacciones.xlsx');
});

test('template download returns excel file', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('batches.template'));

    $response->assertOk();
    $response->assertHeader(
        'content-type',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    );
});
