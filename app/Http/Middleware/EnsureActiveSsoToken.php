<?php

namespace App\Http\Middleware;

use App\Support\ActiveSsoToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveSsoToken
{
    public function handle(Request $request, Closure $next, string $scope = 'central', string $password = 'complete'): Response
    {
        $employee = $request->user('api');
        $token = $request->bearerToken();
        $session = $employee && $token ? ActiveSsoToken::find($token, $employee->id) : null;

        if (! $employee || ! $employee->is_active || ! $session) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        if ($scope === 'central' && $session->application_id !== null) {
            return response()->json(['message' => 'A central SSO session is required.'], Response::HTTP_FORBIDDEN);
        }

        if ($password === 'complete' && $employee->must_change_password) {
            return response()->json(['message' => 'Change your password before continuing.', 'must_change_password' => true], Response::HTTP_FORBIDDEN);
        }

        $request->attributes->set('sso_token', $session);

        return $next($request);
    }
}
