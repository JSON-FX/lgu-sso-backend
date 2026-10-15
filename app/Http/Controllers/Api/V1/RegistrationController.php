<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AppRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Position;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class RegistrationController extends Controller
{
    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'applications' => Application::where('is_active', true)
                ->where('name', '!=', 'Admin App Management System')->orderBy('name')
                ->get(['uuid', 'name', 'description'])
                ->map(fn (Application $application) => [
                    ...$application->only(['uuid', 'name', 'description']),
                    'default_role' => $this->defaultRole($application)->value,
                ]),
            'offices' => Office::where('is_active', true)->orderBy('name')->get(['id', 'name', 'abbreviation']),
            'positions' => Position::where('is_active', true)->orderBy('title')->get(['id', 'title']),
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function store(RegisterRequest $request): JsonResponse
    {
        $input = $request->validated();
        $selected = $input['applications'] ?? [];

        // Retry the username allocation if a concurrent registration reserves it first.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(function () use ($input, $selected): JsonResponse {
                    $applications = Application::whereIn('uuid', $selected)->orderBy('id')->lockForUpdate()->get();
                    if ($applications->count() !== count($selected)
                        || $applications->contains(fn (Application $app) => ! $app->is_active || $app->name === 'Admin App Management System')) {
                        throw ValidationException::withMessages(['applications' => 'One or more selected applications are no longer available. Reload the list and try again.']);
                    }

                    foreach (['office_id' => Office::class, 'position_id' => Position::class] as $field => $model) {
                        if (! empty($input[$field]) && ! $model::whereKey($input[$field])->where('is_active', true)->lockForUpdate()->first()) {
                            throw ValidationException::withMessages([$field => 'This selection is no longer available.']);
                        }
                    }

                    $employee = Employee::create([
                        ...Arr::except($input, ['applications', 'password_confirmation', 'username', 'uuid', 'role', 'roles', 'is_active', 'must_change_password', 'date_employed', 'date_terminated']),
                        'username' => Employee::generateUsername($input['first_name'], $input['last_name']),
                        'password' => Hash::make($input['password']),
                        'is_active' => true,
                        'must_change_password' => false,
                    ]);
                    $grants = $applications->mapWithKeys(fn (Application $app) => [$app->id => ['role' => $this->defaultRole($app)->value]]);
                    $employee->applications()->attach($grants->all());
                    AuditLog::log('self_registered', $employee, metadata: ['applications' => $applications->map(fn (Application $app) => [
                        'uuid' => $app->uuid, 'role' => $this->defaultRole($app)->value,
                    ])->all()]);

                    return response()->json([
                        'username' => $employee->username,
                        'message' => 'Account created. Sign in with your username and chosen password.',
                        'applications' => $applications->map(fn (Application $app) => [
                            'uuid' => $app->uuid, 'name' => $app->name, 'role' => $this->defaultRole($app)->value,
                        ]),
                    ], 201)->header('Cache-Control', 'private, no-store');
                });
            } catch (UniqueConstraintViolationException $exception) {
                if (Employee::withTrashed()->where('email', $input['email'])->exists()) {
                    throw ValidationException::withMessages(['email' => 'This email is already registered. Sign in or contact your SSO administrator.']);
                }
                if ($attempt === 2) {
                    throw $exception;
                }
            }
        }

        throw new \LogicException('Registration retry limit reached.');
    }

    private function defaultRole(Application $application): AppRole
    {
        return $application->name === 'LGU Connect' ? AppRole::Standard : AppRole::Guest;
    }
}
