<?php

namespace App\Http\Middleware;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Employee;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditLogger
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $this->logRequest($request, $response);

        return $response;
    }

    protected function logRequest(Request $request, Response $response): void
    {
        $employee = $request->attributes->get('sso_audit_employee') ?? $request->user();
        $application = $request->attributes->get('application');
        $action = $this->determineAction($request);

        if (! $action) {
            return;
        }

        AuditLog::create([
            'employee_id' => $employee instanceof Employee ? $employee->id : null,
            'application_id' => $application instanceof Application ? $application->id : null,
            'action' => $action,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => [
                'method' => $request->method(),
                'path' => $request->path(),
                'status_code' => $response->getStatusCode(),
            ],
        ]);
    }

    protected function determineAction(Request $request): ?string
    {
        $path = $request->path();
        $method = $request->method();

        if (str_contains($path, 'auth/login') && $method === 'POST') {
            return 'login';
        }

        if (str_contains($path, 'auth/logout')) {
            if (str_contains($path, 'logout-all')) {
                return 'logout_all';
            }

            return 'logout';
        }

        if (str_contains($path, 'auth/refresh')) {
            return 'token_refresh';
        }

        if (str_contains($path, 'sso/validate')) {
            return 'token_validate';
        }

        if (str_contains($path, 'sso/authorize')) {
            return 'app_authorize';
        }

        if (str_contains($path, 'sso/check') && $method === 'GET') {
            return 'sso_check';
        }

        if (str_contains($path, 'sso/cookie-logout') && $method === 'POST') {
            return 'sso_logout';
        }

        return null;
    }
}
