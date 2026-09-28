<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AppRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Application\StoreApplicationRequest;
use App\Http\Requests\Application\UpdateApplicationRequest;
use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ApplicationController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $applications = Application::query()->paginate(15);

        return ApplicationResource::collection($applications);
    }

    public function store(StoreApplicationRequest $request): JsonResponse
    {
        $plainSecret = Str::random(40);

        $application = Application::create([
            ...$request->validated(),
            'client_secret' => Hash::make($plainSecret),
        ]);

        return response()->json([
            'message' => 'Application created successfully.',
            'data' => new ApplicationResource($application),
            'client_secret' => $plainSecret,
        ], 201);
    }

    public function show(Application $application): JsonResponse
    {
        return response()->json([
            'data' => new ApplicationResource($application),
        ]);
    }

    public function update(UpdateApplicationRequest $request, Application $application): JsonResponse
    {
        $application->update($request->validated());

        return response()->json([
            'message' => 'Application updated successfully.',
            'data' => new ApplicationResource($application->fresh()),
        ]);
    }

    public function destroy(Application $application): JsonResponse
    {
        $application->tokens()->update(['revoked_at' => now()]);
        $application->delete();

        return response()->json([
            'message' => 'Application deleted successfully.',
        ]);
    }

    public function regenerateSecret(Application $application): JsonResponse
    {
        $plainSecret = $application->generateNewSecret();

        return response()->json([
            'message' => 'Client secret regenerated successfully.',
            'client_secret' => $plainSecret,
        ]);
    }

    public function employees(Application $application): JsonResponse
    {
        return response()->json([
            'data' => $application->employees()->get()->map(fn (Employee $employee) => [
                'uuid' => $employee->uuid,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'full_name' => $employee->full_name,
                'initials' => $employee->initials,
                'email' => $employee->email,
                'role' => $employee->pivot->role,
            ]),
        ]);
    }

    public function grantAccess(Request $request, Application $application): JsonResponse
    {
        $validated = $request->validate([
            'employee_uuid' => ['required', 'uuid', 'exists:employees,uuid'],
            'role' => ['required', Rule::enum(AppRole::class)],
        ]);
        $employee = Employee::where('uuid', $validated['employee_uuid'])->firstOrFail();
        $application->employees()->syncWithoutDetaching([
            $employee->id => ['role' => $validated['role']],
        ]);

        return response()->json(['message' => 'Application access granted successfully.']);
    }

    public function updateAccess(Request $request, Application $application, Employee $employee): JsonResponse
    {
        $validated = $request->validate(['role' => ['required', Rule::enum(AppRole::class)]]);
        if (! $application->employees()->whereKey($employee->id)->exists()) {
            return response()->json(['message' => 'Employee access not found.'], 404);
        }

        $application->employees()->updateExistingPivot($employee->id, ['role' => $validated['role']]);

        return response()->json(['message' => 'Application access updated successfully.']);
    }

    public function revokeAccess(Application $application, Employee $employee): JsonResponse
    {
        $employee->revokeApplicationAccess($application);

        return response()->json(['message' => 'Application access revoked successfully.']);
    }
}
