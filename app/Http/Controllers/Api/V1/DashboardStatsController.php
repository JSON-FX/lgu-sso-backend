<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;

class DashboardStatsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'totalEmployees' => Employee::count(),
            'activeEmployees' => Employee::where('is_active', true)->count(),
            'totalApplications' => Application::count(),
            'activeApplications' => Application::where('is_active', true)->count(),
            'recentLogins' => AuditLog::where('action', 'login')
                ->where('created_at', '>=', now()->subDays(7))
                ->count(),
        ]);
    }
}
