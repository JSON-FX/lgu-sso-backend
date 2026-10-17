<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(\App\Http\Middleware\GuardSsoBatchRequest::class);
        $middleware->trimStrings(except: [fn (Request $request) => $request->is(\App\Http\Middleware\GuardSsoBatchRequest::PATH)]);
        $middleware->convertEmptyStringsToNull(except: [fn (Request $request) => $request->is(\App\Http\Middleware\GuardSsoBatchRequest::PATH)]);
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '127.0.0.1'));
        $middleware->encryptCookies(except: ['lgu_sso_token']);
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*') ? null : '/login'
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([\App\Support\SsoCapacityException::class]);
        $exceptions->dontReportWhen(fn (\Throwable $exception) => request()->is(\App\Http\Middleware\GuardSsoBatchRequest::PATH));
        $exceptions->render(function (\Throwable $exception, Request $request) {
            if ($exception instanceof \App\Support\SsoCapacityException) {
                return response()->json(['message' => 'SSO verification is temporarily unavailable.'], $exception->status)
                    ->header('Retry-After', (string) $exception->retryAfter)->header('Cache-Control', 'private, no-store');
            }
            if ($request->is(\App\Http\Middleware\GuardSsoBatchRequest::PATH)) {
                $status = $exception instanceof \Illuminate\Validation\ValidationException ? 422
                    : ($exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $exception->getStatusCode() : 503);
                $response = response()->json(['message' => 'Authorization request unavailable or invalid.'], $status >= 500 ? 503 : $status)
                    ->header('Cache-Control', 'private, no-store');
                if ($status >= 500) {
                    \Illuminate\Support\Facades\Log::warning('SSO batch verification unavailable.', ['exception_type' => $exception::class]);
                    $response->headers->set('Retry-After', '1');
                }

                return $response;
            }
        });
        $exceptions->respond(function ($response, $exception, Request $request) {
            if ($request->is(\App\Http\Middleware\GuardSsoBatchRequest::PATH)) {
                $response->headers->set('Cache-Control', 'private, no-store');
            }

            return $response;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, \Throwable $exception) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
