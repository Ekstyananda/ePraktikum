<?php

use App\Http\Middleware\ActiveAccount;
use App\Http\Middleware\AdminOnly;
use App\Http\Middleware\ResolvePortal;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['active' => ActiveAccount::class, 'admin' => AdminOnly::class, 'portal' => ResolvePortal::class]);
        $proxies = array_filter(explode(',', (string) env('TRUSTED_PROXIES', '')));
        if ($proxies) {
            $middleware->trustProxies(at: $proxies);
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Status tokens (single and the device list) must never be kept in the session.
        $exceptions->dontFlash(['token', 'tokens']);
        $exceptions->render(function (QueryException $e, Request $request) {
            if (($e->errorInfo[1] ?? null) !== 1062) {
                return null;
            }

            return $request->expectsJson() ? response()->json(['message' => 'Data duplikat atau berubah. Periksa data terbaru.'], 409) : response()->view('errors.409', ['exception' => $e], 409);
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
