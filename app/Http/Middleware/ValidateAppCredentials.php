<?php

namespace App\Http\Middleware;

use App\Models\Application;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateAppCredentials
{
    public function handle(Request $request, Closure $next): Response
    {
        $clientId = $request->header('X-Client-ID') ?? $request->input('client_id');
        $clientSecret = $request->header('X-Client-Secret') ?? $request->input('client_secret');

        if (! is_string($clientId) || $clientId === '' || ! is_string($clientSecret) || $clientSecret === '') {
            return response()->json([
                'message' => 'Missing client credentials.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $application = Application::query()
            ->where('client_id', $clientId)
            ->where('is_active', true)
            ->first();

        if (! $application || ! $application->validateSecret($clientSecret)) {
            return response()->json([
                'message' => 'Invalid client credentials.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $request->attributes->set('application', $application);

        return $next($request);
    }
}
