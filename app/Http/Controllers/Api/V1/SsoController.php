<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeResource;
use App\Http\Traits\SetsSsoCookie;
use App\Models\Application;
use App\Models\Employee;
use App\Support\ActiveSsoToken;
use App\Support\ApplicationSessionAuthorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;

class SsoController extends Controller
{
    use SetsSsoCookie;

    private function applicationEmployee(Request $request): Employee|JsonResponse
    {
        $token = $request->input('token') ?? $request->bearerToken();
        if (! is_string($token) || $token === '') {
            return response()->json(['valid' => false, 'authorized' => false, 'message' => 'A token is required.'], 400);
        }

        $application = $request->attributes->get('application');
        if (! $application instanceof Application) {
            return response()->json(['valid' => false, 'authorized' => false, 'message' => 'Application access denied.'], 403);
        }
        $decision = app(ApplicationSessionAuthorization::class)->decide($application, [$token])[0];
        if ($decision['status'] !== 200) {
            return response()->json(['valid' => false, 'authorized' => false, 'message' => 'Invalid session or application access denied.'], $decision['status']);
        }
        $request->attributes->set('sso_audit_employee', $decision['employee']);
        $request->attributes->set('sso_authorized_role', $decision['role']->value);

        return $decision['employee'];
    }

    public function validate(Request $request): JsonResponse
    {
        $employee = $this->applicationEmployee($request);
        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $application = $request->attributes->get('application');
        $employee->load(['office', 'position', 'applications' => fn ($query) => $query->where('applications.id', $application->id)]);

        return response()->json(['valid' => true, 'data' => new EmployeeResource($employee)]);
    }

    public function authorize(Request $request): JsonResponse
    {
        $employee = $this->applicationEmployee($request);
        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $application = $request->attributes->get('application');

        return response()->json([
            'authorized' => true,
            'role' => $request->attributes->get('sso_authorized_role'),
            'employee' => ['uuid' => $employee->uuid, 'full_name' => $employee->full_name, 'email' => $employee->email],
        ]);
    }

    public function validateRedirect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => ['required', 'string'],
            'redirect_uri' => ['required', 'string', 'url'],
        ]);

        $application = Application::where('client_id', $validated['client_id'])
            ->where('is_active', true)
            ->first();

        if (! $application) {
            return response()->json([
                'valid' => false,
                'message' => 'Application not found or inactive.',
            ], 404);
        }

        $allowedUris = $application->redirect_uris ?? [];

        if (! in_array($validated['redirect_uri'], $allowedUris, true)) {
            return response()->json([
                'valid' => false,
                'message' => 'Redirect URI is not allowed for this application.',
            ], 403);
        }

        return response()->json([
            'valid' => true,
            'application_name' => $application->name,
        ]);
    }

    public function employee(Request $request): JsonResponse
    {
        $employee = $this->applicationEmployee($request);
        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $application = $request->attributes->get('application');
        $employee->load(['office', 'position', 'applications' => fn ($query) => $query->where('applications.id', $application->id)]);

        return response()->json(['data' => new EmployeeResource($employee), 'role' => $request->attributes->get('sso_authorized_role')]);
    }

    public function sessionCheck(Request $request): JsonResponse
    {
        $token = $request->cookie(config('sso.cookie_name'));
        if (! is_string($token) || $token === '') {
            return response()->json(['authenticated' => false]);
        }

        try {
            $payload = JWTAuth::setToken($token)->getPayload();
            $employee = Employee::find($payload->get('sub'));
            $session = $employee ? ActiveSsoToken::find($token, $employee->id) : null;
            if ($employee?->is_active && $session && $session->application_id === null) {
                return response()->json(['authenticated' => true]);
            }
        } catch (JWTException $exception) {
            // Expired and malformed cookies are cleared in the same way.
        }

        return $this->clearSsoCookie(response()->json(['authenticated' => false]));
    }

    public function check(): JsonResponse
    {
        return response()->json(['message' => 'Cookie-based consumer sign-in is retired. Use authorization code exchange.'], 410);
    }

    public function cookieLogout(): JsonResponse
    {
        return response()->json(['message' => 'Cookie-based consumer logout is retired. Revoke the application bearer through /auth/logout.'], 410);
    }

    public function employees(Request $request): JsonResponse
    {
        $application = $request->attributes->get('application');
        $employees = $application->employees()->where('is_active', true)->where('must_change_password', false)
            ->with(['office', 'position'])->get();

        return response()->json($employees->map(fn (Employee $employee) => [
            'uuid' => $employee->uuid,
            'username' => $employee->username,
            'email' => $employee->email,
            'first_name' => $employee->first_name,
            'middle_name' => $employee->middle_name,
            'last_name' => $employee->last_name,
            'full_name' => $employee->full_name,
            'position' => $employee->position?->title,
            'office_name' => $employee->office?->name,
            'is_active' => $employee->is_active,
        ]));
    }
}
