<?php

namespace App\Http\Middleware;

use App\Enums\AppRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSsoAdministrator
{
    public function handle(Request $request, Closure $next): Response
    {
        $employee = $request->user('api');

        if (! $employee || ! $employee->applications()
            ->where('name', 'Admin App Management System')
            ->where('is_active', true)
            ->wherePivot('role', AppRole::SuperAdministrator->value)
            ->exists()) {
            return response()->json(['message' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
