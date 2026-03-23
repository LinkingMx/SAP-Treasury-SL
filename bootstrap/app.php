<?php

umask(0002);

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Force JSON responses for API/AJAX requests
        $exceptions->render(function (Throwable $e, Request $request) {
            // Let Laravel handle ValidationException natively (422 responses)
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                return null;
            }

            if ($request->expectsJson() || $request->is('treasury/ai/*')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'exception' => get_class($e),
                ], 500);
            }
        });
    })->create();
