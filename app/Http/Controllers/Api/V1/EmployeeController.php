<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AppRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\GrantAppAccessRequest;
use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Services\EmployeeCreation;
use App\Support\ActiveSsoToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'in:active,inactive'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Employee::query()->with(['office', 'position', 'applications']);

        foreach (preg_split('/\s+/', trim($filters['search'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $term) {
            $query->where(function ($query) use ($term) {
                $like = "%{$term}%";

                $query->where('first_name', 'like', $like)
                    ->orWhere('middle_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('email', 'like', $like);
            });
        }

        if (isset($filters['status'])) {
            $query->where('is_active', $filters['status'] === 'active');
        }

        $employees = $query->orderBy('id')->paginate($filters['per_page'] ?? 15);

        return EmployeeResource::collection($employees);
    }

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $data = $request->validated();
        $initialPassword = Str::random(20);
        $employee = app(EmployeeCreation::class)->create([
            ...$data,
            'password' => $initialPassword,
            'must_change_password' => true,
        ]);

        return response()->json([
            'message' => 'Employee created successfully.',
            'data' => new EmployeeResource($employee),
            'initial_password' => $initialPassword,
        ], 201);
    }

    public function show(Employee $employee): JsonResponse
    {
        $employee->load(['office', 'position', 'applications']);

        return response()->json([
            'data' => new EmployeeResource($employee),
        ]);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): JsonResponse
    {
        $employee->update($request->validated());

        return response()->json([
            'message' => 'Employee updated successfully.',
            'data' => new EmployeeResource($employee->fresh()),
        ]);
    }

    public function resetPassword(Request $request, Employee $employee): JsonResponse
    {
        $actorId = $request->user('api')->id;
        abort_if($actorId === $employee->id, 422, 'Use Change password to update your own password.');

        return DB::transaction(function () use ($request, $employee, $actorId): JsonResponse {
            $employees = Employee::whereIn('id', [$actorId, $employee->id])->orderBy('id')->lockForUpdate()->get();
            $actor = $employees->firstWhere('id', $actorId);
            $target = $employees->firstWhere('id', $employee->id);
            $session = $actor ? ActiveSsoToken::find($request->bearerToken(), $actor->id) : null;
            abort_unless($actor && $actor->is_active && $session, 401, 'Unauthenticated.');
            abort_unless($session->application_id === null && ! $actor->must_change_password
                && $actor->applications()->where('name', 'Admin App Management System')->where('is_active', true)
                    ->wherePivot('role', AppRole::SuperAdministrator->value)->exists(), 403, 'Forbidden.');
            abort_unless($target, 404, 'Employee not found.');

            $temporaryPassword = Str::random(20);
            $target->update(['password' => $temporaryPassword, 'must_change_password' => true]);
            $target->revokeSessions();
            AuditLog::log('employee_password_reset', $actor, metadata: ['employee_uuid' => $target->uuid]);

            return response()->json([
                'message' => 'Password reset. The employee must choose a new password at next sign-in.',
                'data' => ['uuid' => $target->uuid, 'username' => $target->username, 'must_change_password' => true],
                'temporary_password' => $temporaryPassword,
            ])->header('Cache-Control', 'private, no-store');
        });
    }

    public function destroy(Employee $employee): JsonResponse
    {
        $employee->tokens()->update(['revoked_at' => now()]);
        $employee->delete();

        return response()->json([
            'message' => 'Employee deleted successfully.',
        ]);
    }

    public function applications(Employee $employee): JsonResponse
    {
        $employee->load('applications');

        return response()->json([
            'data' => $employee->applications->map(fn ($app) => [
                'uuid' => $app->uuid,
                'name' => $app->name,
                'role' => $app->pivot->role,
                'granted_at' => $app->pivot->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function grantAccess(GrantAppAccessRequest $request, Employee $employee): JsonResponse
    {
        $application = Application::where('uuid', $request->application_uuid)->firstOrFail();

        $employee->applications()->syncWithoutDetaching([
            $application->id => ['role' => $request->role],
        ]);

        return response()->json([
            'message' => 'Application access granted successfully.',
        ]);
    }

    public function updateAccess(Employee $employee, Application $application): JsonResponse
    {
        $validated = request()->validate([
            'role' => ['required', Rule::enum(AppRole::class)],
        ]);

        $employee->applications()->updateExistingPivot($application->id, [
            'role' => $validated['role'],
        ]);

        return response()->json([
            'message' => 'Application access updated successfully.',
        ]);
    }

    public function revokeAccess(Employee $employee, Application $application): JsonResponse
    {
        $employee->revokeApplicationAccess($application);

        return response()->json([
            'message' => 'Application access revoked successfully.',
        ]);
    }
}
