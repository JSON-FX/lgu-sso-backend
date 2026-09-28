<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\EmployeeResource;
use App\Http\Traits\SetsSsoCookie;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\OAuthToken;
use App\Support\ActiveSsoToken;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    use SetsSsoCookie;

    private const LOGIN_ACCOUNT_MAX_ATTEMPTS = 5;

    private const LOGIN_IP_MAX_ATTEMPTS = 20;

    private const LOGIN_DECAY_SECONDS = 900;

    public function login(LoginRequest $request): JsonResponse
    {
        $limiter = app(RateLimiter::class);
        $accountKey = 'sso_login:account:'.hash('sha256', mb_strtolower(trim($request->username), 'UTF-8'));
        $ipKey = 'sso_login:ip:'.hash('sha256', (string) $request->ip());

        $accountLimited = $limiter->tooManyAttempts($accountKey, self::LOGIN_ACCOUNT_MAX_ATTEMPTS);
        $ipLimited = $limiter->tooManyAttempts($ipKey, self::LOGIN_IP_MAX_ATTEMPTS);

        if ($accountLimited || $ipLimited) {
            $retryAfter = max(
                $accountLimited ? $limiter->availableIn($accountKey) : 0,
                $ipLimited ? $limiter->availableIn($ipKey) : 0
            );

            return response()->json([
                'message' => 'Too many login attempts. Please try again later.',
                'retry_after' => $retryAfter,
            ], 429)->header('Retry-After', $retryAfter);
        }

        $employee = Employee::query()
            ->where('username', $request->username)
            ->where('is_active', true)
            ->first();

        if (! $employee || ! Hash::check($request->password, $employee->password)) {
            $limiter->hit($accountKey, self::LOGIN_DECAY_SECONDS);
            $limiter->hit($ipKey, self::LOGIN_DECAY_SECONDS);

            return response()->json([
                'message' => 'Invalid credentials.',
            ], 401);
        }

        $limiter->clear($accountKey);

        $token = JWTAuth::fromUser($employee);

        OAuthToken::create([
            'employee_id' => $employee->id,
            'access_token' => hash('sha256', $token),
        ]);

        AuditLog::log('login', $employee);

        $response = response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => (int) config('jwt.ttl') * 60,
            'employee' => new EmployeeResource($employee),
        ]);

        return $this->attachSsoCookie($response, $token);
    }

    public function logout(): JsonResponse
    {
        $employee = auth()->user();
        $token = JWTAuth::getToken();

        if ($token) {
            DB::transaction(function () use ($employee, $token): void {
                Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
                $hashedToken = hash('sha256', $token->get());
                OAuthToken::query()->where('access_token', $hashedToken)->whereNull('revoked_at')
                    ->update(['revoked_at' => now()]);
                DB::table('sso_authorization_codes')->where('source_token_hash', $hashedToken)->delete();
            });
            JWTAuth::invalidate($token);
        }

        AuditLog::log('logout', $employee);

        $response = response()->json([
            'message' => 'Successfully logged out.',
        ]);

        return request()->attributes->get('sso_token')->application_id === null
            ? $this->clearSsoCookie($response) : $response;
    }

    public function logoutAll(): JsonResponse
    {
        $employee = auth()->user();

        DB::transaction(function () use ($employee): void {
            Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail()->revokeSessions();
        });

        $token = JWTAuth::getToken();
        if ($token) {
            JWTAuth::invalidate($token);
        }

        AuditLog::log('logout_all', $employee);

        $response = response()->json([
            'message' => 'Successfully logged out from all sessions.',
        ]);

        return $this->clearSsoCookie($response);
    }

    public function refresh(): JsonResponse
    {
        $employeeId = auth()->id();
        $oldToken = request()->bearerToken();

        return DB::transaction(function () use ($employeeId, $oldToken): JsonResponse {
            $employee = Employee::whereKey($employeeId)->lockForUpdate()->firstOrFail();
            $session = ActiveSsoToken::find($oldToken, $employee->id);
            if (! $session || $session->application_id !== null || ! $employee->is_active || $employee->must_change_password) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            $session->revoke();
            $newToken = JWTAuth::setToken($oldToken)->refresh();
            OAuthToken::create(['employee_id' => $employee->id, 'access_token' => hash('sha256', $newToken)]);
            AuditLog::log('token_refresh', $employee);

            return $this->attachSsoCookie(response()->json([
                'access_token' => $newToken, 'token_type' => 'bearer', 'expires_in' => (int) config('jwt.ttl') * 60,
            ]), $newToken);
        });
    }

    public function me(): JsonResponse
    {
        $employee = auth()->user();
        $employee->load(['office', 'applications' => fn ($query) => $query->where('is_active', true)]);

        return response()->json([
            'data' => new EmployeeResource($employee),
        ]);
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $employeeId = auth()->id();

        return DB::transaction(function () use ($employeeId, $request): JsonResponse {
            $employee = Employee::whereKey($employeeId)->lockForUpdate()->firstOrFail();
            if (! ActiveSsoToken::exists($request->bearerToken(), $employee->id)) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
            if (! Hash::check($request->current_password, $employee->password)) {
                return response()->json(['message' => 'Current password is incorrect.'], 422);
            }
            $employee->update(['password' => Hash::make($request->new_password), 'must_change_password' => false]);
            $employee->revokeSessions(hash('sha256', $request->bearerToken()));

            return response()->json(['message' => 'Password changed successfully.']);
        });
    }
}
