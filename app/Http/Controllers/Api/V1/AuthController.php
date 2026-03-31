<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\EmployeeResource;
use App\Http\Traits\SetsSsoCookie;
use App\Models\AuditLog;
use App\Events\EmployeeCreated;
use App\Models\Employee;
use App\Models\OAuthToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    use SetsSsoCookie;

    public function login(LoginRequest $request): JsonResponse
    {
        $employee = Employee::query()
            ->where('username', $request->username)
            ->where('is_active', true)
            ->first();

        if (! $employee || ! Hash::check($request->password, $employee->password)) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 401);
        }

        $token = JWTAuth::fromUser($employee);

        OAuthToken::create([
            'employee_id' => $employee->id,
            'access_token' => hash('sha256', $token),
        ]);

        AuditLog::log('login', $employee);

        $response = response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'employee' => new EmployeeResource($employee),
        ]);

        return $this->attachSsoCookie($response, $token);
    }

    public function register(RegisterRequest $request)
    {
        // Normalize names to Title Case
        $firstName = mb_convert_case($request->first_name, MB_CASE_TITLE, 'UTF-8');
        $middleName = $request->middle_name ? mb_convert_case($request->middle_name, MB_CASE_TITLE, 'UTF-8') : null;
        $lastName = mb_convert_case($request->last_name, MB_CASE_TITLE, 'UTF-8');

        $username = Employee::generateUsername($firstName, $lastName);

        // Default password: first initial + full last name (lowercased, no spaces)
        $firstInitial = strtolower(substr($firstName, 0, 1));
        $normalizedLastName = strtolower(str_replace(' ', '', $lastName));
        $defaultPassword = $firstInitial . $normalizedLastName;

        $employee = Employee::create([
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'last_name' => $lastName,
            'username' => $username,
            'email' => $username . '@lgu.gov.ph',
            'password' => Hash::make($defaultPassword),
            'must_change_password' => true,
            'is_active' => true,
            'birthday' => '2000-01-01',
            'civil_status' => 'single',
            'nationality' => 'Filipino',
            'residence' => '',
            'position_id' => null,
        ]);

        // Auto-grant guest role on all active applications
        $applications = \App\Models\Application::where('is_active', true)->get();
        foreach ($applications as $app) {
            $employee->applications()->attach($app->id, [
                'role' => \App\Enums\AppRole::Guest->value,
            ]);
        }

        EmployeeCreated::dispatch($employee);

        return response()->json([
            'username' => $username,
            'message' => 'Registration successful',
        ], 201);
    }

    public function logout(): JsonResponse
    {
        $employee = auth()->user();
        $token = JWTAuth::getToken();

        if ($token) {
            $hashedToken = hash('sha256', $token->get());
            OAuthToken::query()
                ->where('access_token', $hashedToken)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            JWTAuth::invalidate($token);
        }

        AuditLog::log('logout', $employee);

        $response = response()->json([
            'message' => 'Successfully logged out.',
        ]);

        return $this->clearSsoCookie($response);
    }

    public function logoutAll(): JsonResponse
    {
        $employee = auth()->user();

        OAuthToken::query()
            ->where('employee_id', $employee->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

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
        $employee = auth()->user();
        $oldToken = JWTAuth::getToken();

        if ($oldToken) {
            $hashedOldToken = hash('sha256', $oldToken->get());
            OAuthToken::query()
                ->where('access_token', $hashedOldToken)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);
        }

        $newToken = JWTAuth::refresh();

        OAuthToken::create([
            'employee_id' => $employee->id,
            'access_token' => hash('sha256', $newToken),
        ]);

        AuditLog::log('token_refresh', $employee);

        $response = response()->json([
            'access_token' => $newToken,
            'token_type' => 'bearer',
        ]);

        return $this->attachSsoCookie($response, $newToken);
    }

    public function me(): JsonResponse
    {
        $employee = auth()->user();
        $employee->load(['office', 'applications']);

        return response()->json([
            'data' => new EmployeeResource($employee),
        ]);
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $employee = auth()->user();

        if (! Hash::check($request->current_password, $employee->password)) {
            return response()->json([
                'message' => 'Current password is incorrect.',
            ], 422);
        }

        $employee->update([
            'password' => Hash::make($request->new_password),
            'must_change_password' => false,
        ]);

        return response()->json([
            'message' => 'Password changed successfully.',
        ]);
    }
}
