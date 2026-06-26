<?php

use App\Models\Branch;

it('persists the payment_branch attribute', function () {
    $branch = Branch::factory()->create([
        'payment_branch' => 'MOCHOMOS_POLANCO',
    ]);

    expect($branch->fresh()->payment_branch)->toBe('MOCHOMOS_POLANCO');
});

it('allows payment_branch to be null', function () {
    $branch = Branch::factory()->create([
        'payment_branch' => null,
    ]);

    expect($branch->fresh()->payment_branch)->toBeNull();
});
