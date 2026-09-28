<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Employee;
use App\Models\OAuthToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

class SsoAuthorizationCodeController extends Controller
{
    public function issue(Request $request): JsonResponse
    {
        $input = $request->validate([
            'client_id' => ['required', 'string'],
            'redirect_uri' => ['required', 'string', 'url'],
        ]);

        $application = Application::where('client_id', $input['client_id'])->where('is_active', true)->first();
        if (! $application || ! in_array($input['redirect_uri'], $application->redirect_uris ?? [], true)) {
            return response()->json(['message' => 'Invalid application or redirect URI.'], 403);
        }

        return DB::transaction(function () use ($request, $input, $application): JsonResponse {
            $application = Application::whereKey($application->id)->where('is_active', true)->lockForUpdate()->first();
            if (! $application || ! in_array($input['redirect_uri'], $application->redirect_uris ?? [], true)) {
                return response()->json(['message' => 'Invalid application or redirect URI.'], 403);
            }
            $employee = Employee::whereKey($request->user('api')->id)->lockForUpdate()->firstOrFail();
            $source = \App\Support\ActiveSsoToken::find($request->bearerToken(), $employee->id);
            if (! $source || $source->application_id !== null || ! $employee->is_active
                || $employee->must_change_password || ! $employee->hasAccessTo($application)) {
                return response()->json(['message' => 'Employee does not have access to this application.'], 403);
            }

            $code = Str::random(64);
            DB::table('sso_authorization_codes')->insert([
                'code_hash' => hash('sha256', $code),
                'employee_id' => $employee->id,
                'application_id' => $application->id,
                'source_token_hash' => hash('sha256', $request->bearerToken()),
                'redirect_uri' => $input['redirect_uri'],
                'expires_at' => now()->addMinute(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json(['code' => $code, 'expires_in' => 60])->header('Cache-Control', 'no-store');
        });
    }

    public function exchange(Request $request): JsonResponse
    {
        $input = $request->validate([
            'code' => ['required', 'string'],
            'redirect_uri' => ['required', 'string', 'url'],
        ]);

        $application = $request->attributes->get('application');
        if (! $application instanceof Application) {
            return response()->json(['message' => 'Invalid application.'], 401);
        }

        return DB::transaction(function () use ($input, $application): JsonResponse {
            $currentApplication = Application::whereKey($application->id)->where('is_active', true)->lockForUpdate()->first();
            if (! $currentApplication || ! hash_equals($application->client_secret, $currentApplication->client_secret)
                || ! in_array($input['redirect_uri'], $currentApplication->redirect_uris ?? [], true)) {
                return response()->json(['message' => 'Application credentials or redirect changed.'], 401);
            }
            $query = DB::table('sso_authorization_codes')
                ->where('code_hash', hash('sha256', $input['code']))
                ->where('application_id', $application->id)
                ->where('redirect_uri', $input['redirect_uri'])
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now());

            $code = (clone $query)->first();
            $employee = $code ? Employee::whereKey($code->employee_id)->lockForUpdate()->first() : null;
            if (! $code || $query->update(['consumed_at' => now(), 'updated_at' => now()]) !== 1) {
                return response()->json(['message' => 'Invalid or expired authorization code.'], 401);
            }

            $sourceTokenActive = OAuthToken::where('access_token', $code->source_token_hash)
                ->where('employee_id', $code->employee_id)
                ->whereNull('application_id')
                ->whereNull('revoked_at')
                ->exists();
            if (! $sourceTokenActive || ! $employee || ! $employee->is_active || $employee->must_change_password || ! $employee->hasAccessTo($application)) {
                return response()->json(['message' => 'Employee access has been revoked.'], 403);
            }

            $token = JWTAuth::fromUser($employee);
            OAuthToken::create([
                'employee_id' => $employee->id,
                'application_id' => $application->id,
                'access_token' => hash('sha256', $token),
            ]);

            return response()->json(['access_token' => $token, 'token_type' => 'bearer', 'expires_in' => (int) config('jwt.ttl') * 60])->header('Cache-Control', 'no-store');
        });
    }
}
