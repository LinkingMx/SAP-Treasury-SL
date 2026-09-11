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

            if (! $request->expectsJson() && ! $request->is('treasury/ai/*')) {
                return null;
            }

            // Keep the real status code. Collapsing everything into 500 hid an
            // expired session behind an opaque "server error" that Laravel never
            // logs (TokenMismatchException is not reportable), leaving no trace
            // of why an upload failed.
            $status = match (true) {
                $e instanceof \Illuminate\Session\TokenMismatchException => 419,
                $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface => $e->getStatusCode(),
                default => 500,
            };

            $message = match ($status) {
                419 => 'Tu sesión expiró. Recarga la página (F5) e inténtalo de nuevo.',
                413 => 'El archivo es demasiado grande para el servidor.',
                403 => 'No tienes permiso para realizar esta acción.',
                // Redact as a backstop: providers embed credentials in their own
                // error text (Google prints the API key in "Consumer 'api_key:...'
                // has been suspended"), and this handler is the last thing between
                // an exception and the user's browser.
                default => \App\Services\Ai\AiErrorTranslator::redact($e->getMessage()),
            };

            return response()->json([
                'success' => false,
                'message' => $message,
                'exception' => get_class($e),
            ], $status);
        });
    })->create();
