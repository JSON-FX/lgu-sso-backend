<?php

namespace App\Services;

use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmployeeCreation
{
    public const ACCOUNT_EXISTS = 'An account already exists. Please head over to MIS for account retrieval.';

    public function create(array $attributes): Employee
    {
        $birthday = Carbon::parse($attributes['birthday'])->toDateString();
        $firstName = $this->normalizeName($attributes['first_name']);
        $lastName = $this->normalizeName($attributes['last_name']);
        $identity = hash('sha256', json_encode([$firstName, $lastName, $birthday], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($attributes, $birthday, $firstName, $lastName, $identity): Employee {
            // A unique database row serializes this identity even when no employee exists yet.
            DB::table('employee_creation_locks')->upsert([['identity_hash' => $identity]], ['identity_hash'], ['identity_hash']);
            DB::table('employee_creation_locks')->where('identity_hash', $identity)->lockForUpdate()->firstOrFail();

            $existing = Employee::withTrashed()->where('birthday', '>=', $birthday)
                ->where('birthday', '<', Carbon::parse($birthday)->addDay()->toDateString())->lockForUpdate()
                ->get(['first_name', 'last_name'])
                ->contains(fn (Employee $employee) => $this->normalizeName($employee->first_name) === $firstName
                    && $this->normalizeName($employee->last_name) === $lastName);

            if ($existing) {
                throw ValidationException::withMessages(['account' => self::ACCOUNT_EXISTS]);
            }

            $username = Employee::generateUsername(Str::squish($attributes['first_name']), Str::squish($attributes['last_name']));

            return Employee::create([
                ...$attributes,
                'birthday' => $birthday,
                'username' => $username,
                'email' => $attributes['email'] ?? "{$username}@lgu.gov.ph",
            ]);
        }, 3);
    }

    private function normalizeName(string $name): string
    {
        return Str::lower(Str::squish($name));
    }
}
