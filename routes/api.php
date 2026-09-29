<?php

use App\Http\Controllers\Api\V1\ApplicationController;
use App\Http\Controllers\Api\V1\AuditController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DashboardStatsController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\OfficeController;
use App\Http\Controllers\Api\V1\PortalController;
use App\Http\Controllers\Api\V1\PositionController;
use App\Http\Controllers\Api\V1\SsoAuthorizationCodeController;
use App\Http\Controllers\Api\V1\SsoController;
use App\Http\Controllers\Api\V1\SsoDirectoryController;
use App\Http\Middleware\AuditLogger;
use App\Http\Middleware\EnsureActiveSsoToken;
use App\Http\Middleware\EnsureSsoAdministrator;
use App\Http\Middleware\PerAppRateLimit;
use App\Http\Middleware\PrivateDirectoryResponse;
use App\Http\Middleware\ValidateAppCredentials;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('login', [AuthController::class, 'login'])
            ->middleware(AuditLogger::class);

        Route::middleware(['auth:api', EnsureActiveSsoToken::class.':any,allow'])->group(function () {
            Route::post('logout', [AuthController::class, 'logout'])
                ->middleware(AuditLogger::class);
            Route::post('logout-all', [AuthController::class, 'logoutAll'])
                ->middleware([EnsureActiveSsoToken::class.':central,allow', AuditLogger::class]);
            Route::post('refresh', [AuthController::class, 'refresh'])
                ->middleware([EnsureActiveSsoToken::class, AuditLogger::class]);
            Route::get('me', [AuthController::class, 'me'])->middleware(EnsureActiveSsoToken::class.':central,allow');
            Route::post('change-password', [AuthController::class, 'changePassword'])->middleware(EnsureActiveSsoToken::class.':central,allow');
        });
    });

    Route::middleware(['auth:api', EnsureActiveSsoToken::class])->group(function () {
        Route::middleware(EnsureSsoAdministrator::class)->group(function () {
            Route::get('stats/dashboard', DashboardStatsController::class);
            Route::apiResource('employees', EmployeeController::class);
            Route::prefix('employees/{employee}')->group(function () {
                Route::get('applications', [EmployeeController::class, 'applications']);
                Route::post('applications', [EmployeeController::class, 'grantAccess']);
                Route::put('applications/{application}', [EmployeeController::class, 'updateAccess']);
                Route::delete('applications/{application}', [EmployeeController::class, 'revokeAccess']);
            });

            Route::apiResource('applications', ApplicationController::class);
            Route::post('applications/{application}/regenerate-secret', [ApplicationController::class, 'regenerateSecret']);
            Route::get('applications/{application}/employees', [ApplicationController::class, 'employees']);
            Route::post('applications/{application}/employees', [ApplicationController::class, 'grantAccess']);
            Route::put('applications/{application}/employees/{employee}', [ApplicationController::class, 'updateAccess']);
            Route::delete('applications/{application}/employees/{employee}', [ApplicationController::class, 'revokeAccess']);

            Route::prefix('audit')->group(function () {
                Route::get('logs', [AuditController::class, 'index']);
                Route::get('employees/{employee}/logs', [AuditController::class, 'employeeLogs']);
                Route::get('applications/{application}/logs', [AuditController::class, 'applicationLogs']);
            });

            Route::get('offices', [OfficeController::class, 'index']);
            Route::get('offices/{office}', [OfficeController::class, 'show']);
            Route::get('positions', [PositionController::class, 'index']);
        });

        // Portal (self-service)
        Route::get('portal/profile', [PortalController::class, 'profile']);
        Route::put('portal/profile', [PortalController::class, 'updateProfile']);
        Route::get('portal/applications', [PortalController::class, 'applications']);
        Route::get('portal/offices', [OfficeController::class, 'index']);
        Route::get('portal/positions', [PositionController::class, 'index']);
    });

    Route::prefix('sso')->group(function () {
        Route::prefix('directory')->middleware([PrivateDirectoryResponse::class, ValidateAppCredentials::class, PerAppRateLimit::class])->group(function () {
            Route::get('/', [SsoDirectoryController::class, 'index']);
            Route::get('{uuid}', [SsoDirectoryController::class, 'show']);
        });

        // Portal redirect validation and central session inspection.
        Route::post('validate-redirect', [SsoController::class, 'validateRedirect']);
        Route::get('session-check', [SsoController::class, 'sessionCheck']);
        Route::post('code', [SsoAuthorizationCodeController::class, 'issue'])
            ->middleware(['auth:api', EnsureActiveSsoToken::class]);

        Route::middleware([ValidateAppCredentials::class, PerAppRateLimit::class])->group(function () {
            Route::post('exchange', [SsoAuthorizationCodeController::class, 'exchange']);
            Route::post('validate', [SsoController::class, 'validate'])
                ->middleware(AuditLogger::class);
            Route::post('authorize', [SsoController::class, 'authorize'])
                ->middleware(AuditLogger::class);
            Route::get('check', [SsoController::class, 'check'])
                ->middleware(AuditLogger::class);
            Route::get('employees', [SsoController::class, 'employees']);
            Route::post('cookie-logout', [SsoController::class, 'cookieLogout'])
                ->middleware(AuditLogger::class);

            Route::get('employee', [SsoController::class, 'employee']);
        });
    });

    Route::prefix('locations')->group(function () {
        Route::get('regions', [LocationController::class, 'regions']);
        Route::get('regions/{code}/provinces', [LocationController::class, 'provincesByRegion']);
        Route::get('provinces', [LocationController::class, 'provinces']);
        Route::get('provinces/{code}/cities', [LocationController::class, 'cities']);
        Route::get('cities/{code}/barangays', [LocationController::class, 'barangays']);
    });
});
