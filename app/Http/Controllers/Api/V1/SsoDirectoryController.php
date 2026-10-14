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
            'page' => ['sometimes', 'integer', 'between:1,10000'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ]);

        $query = $this->employees($request, $input['roles']);
        foreach (preg_split('/\s+/u', trim($input['search'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $term) {
            // An explicit escape character keeps literal %, _ and ! portable across SQLite and MySQL.
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';
            $query->where(function ($names) use ($pattern): void {
                foreach (['first_name', 'middle_name', 'last_name', 'suffix'] as $column) {
                    $names->orWhereRaw("LOWER(employees.{$column}) LIKE ? ESCAPE '!'", [$pattern]);
                }
            });
        }

        $page = $query->orderBy('employees.last_name')->orderBy('employees.first_name')->orderBy('employees.uuid')
            ->simplePaginate((int) ($input['per_page'] ?? 20), ['employees.*'], 'page', (int) ($input['page'] ?? 1));

        return response()->json([
            'data' => $page->getCollection()->map(fn (Employee $employee) => $this->display($employee))->values(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'has_more' => $page->hasMorePages()],
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
            ->wherePivotIn('role', $roles)->with('office');
    }

    private function display(Employee $employee): array
    {
        return [
            'uuid' => $employee->uuid,
            'full_name' => $employee->full_name,
            'initials' => $employee->initials,
            'role' => $employee->pivot->role,
            'office_name' => $employee->office?->name,
            'office_abbreviation' => $employee->office?->abbreviation,
        ];
    }
}
