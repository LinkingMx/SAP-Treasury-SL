<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return Inertia::render('welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');

    Route::get('tesoreria', function () {
        return Inertia::render('tesoreria/index', [
            'branches' => auth()->user()->branches()->get(['branches.id', 'branches.name']),
        ]);
    })->name('tesoreria');
});

require __DIR__.'/settings.php';
