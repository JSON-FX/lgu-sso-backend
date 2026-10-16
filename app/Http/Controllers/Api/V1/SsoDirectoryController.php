<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AppRole;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SsoDirectoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            ...$this->roleRules(),
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'office_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'position_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'between:1,10000'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ]);

        $query = $this->employees($request, $input['roles']);
        foreach (['office_id', 'position_id'] as $filter) {
            if (isset($input[$filter])) {
                $query->where('employees.'.$filter, $input[$filter]);
            }
        }
        foreach (preg_split('/\s+/u', trim($input['search'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $term) {
            // An explicit escape character keeps literal %, _ and ! portable across SQLite and MySQL.
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';
            $query->where(function ($matches) use ($pattern): void {
                foreach (['first_name', 'middle_name', 'last_name', 'suffix'] as $column) {
                    $matches->orWhereRaw("LOWER(employees.{$column}) LIKE ? ESCAPE '!'", [$pattern]);
                }
                $matches->orWhereHas('office', fn ($office) => $office
                    ->whereRaw("LOWER(offices.name) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(offices.abbreviation) LIKE ? ESCAPE '!'", [$pattern]));
                $matches->orWhereHas('position', fn ($position) => $position
                    ->whereRaw("LOWER(positions.title) LIKE ? ESCAPE '!'", [$pattern]));
            });
        }

        $page = $query->orderBy('employees.last_name')->orderBy('employees.first_name')->orderBy('employees.uuid')
            ->simplePaginate((int) ($input['per_page'] ?? 20), ['employees.*'], 'page', (int) ($input['page'] ?? 1));

        return response()->json([
            'data' => $page->getCollection()->map(fn (Employee $employee) => $this->display($employee))->values(),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'has_more' => $page->hasMorePages(),
                ...((isset($input['office_id']) || isset($input['position_id'])) ? ['filters_applied' => true] : []),
            ],
        ]);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $input = $request->validate($this->roleRules());
        $employee = Str::isUuid($uuid)
            ? $this->employees($request, $input['roles'])->where('employees.uuid', $uuid)->first()
            : null;

        if (! $employee) {
            return response()->json(['message' => 'Employee not found.'], 404);
        }

        return response()->json(['data' => $this->display($employee)]);
    }

    public function avatar(Request $request, string $uuid, \App\Services\EmployeeAvatars $avatars)
    {
        $input = $request->validate($this->roleRules());
        $employee = Str::isUuid($uuid)
            ? $this->employees($request, $input['roles'])->where('employees.uuid', $uuid)->first()
            : null;
        abort_unless($employee, 404, 'Employee not found.');

        return $avatars->response($employee);
    }

    private function roleRules(): array
    {
        return [
            'roles' => ['required', 'array', 'min:1', 'max:4'],
            'roles.*' => ['required', 'string', 'distinct', Rule::enum(AppRole::class)],
        ];
    }

    private function employees(Request $request, array $roles): BelongsToMany
    {
        return $request->attributes->get('application')->employees()
            ->where('employees.is_active', true)->where('employees.must_change_password', false)
            ->wherePivotIn('role', $roles)->with(['office', 'position']);
    }

    private function display(Employee $employee): array
    {
        return [
            'uuid' => $employee->uuid,
            'full_name' => $employee->full_name,
            'initials' => $employee->initials,
            'avatar_version' => $employee->avatar_version,
            'avatar_endpoint' => $employee->avatar_version ? '/api/v1/sso/directory/'.$employee->uuid.'/avatar' : null,
            'role' => $employee->pivot->role,
            'office_id' => $employee->office?->id,
            'office_name' => $employee->office?->name,
            'office_abbreviation' => $employee->office?->abbreviation,
            'position_id' => $employee->position?->id,
            'position_title' => $employee->position?->title,
        ];
    }
}
