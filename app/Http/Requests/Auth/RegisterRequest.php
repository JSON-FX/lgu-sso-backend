<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Employee\StoreEmployeeRequest;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends StoreEmployeeRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'email' => ['required', 'email', 'max:255', 'unique:employees,email'],
            'password' => ['required', 'string', 'max:72', 'confirmed', Password::min(8)->letters()->numbers(), function (string $attribute, mixed $value, Closure $fail) {
                if (is_string($value) && strlen($value) > 72) {
                    $fail('Use a password no longer than 72 UTF-8 bytes.');
                }
            }],
            'password_confirmation' => ['required', 'string', 'max:72'],
            'applications' => ['sometimes', 'array', 'max:100'],
            'applications.*' => ['required', 'uuid', 'distinct'],
            'office_id' => ['nullable', 'integer', Rule::exists('offices', 'id')->where('is_active', true)],
            'position_id' => ['nullable', 'integer', Rule::exists('positions', 'id')->where('is_active', true)],
            'date_employed' => ['prohibited'],
            'date_terminated' => ['prohibited'],
            'username' => ['prohibited'],
            'uuid' => ['prohibited'],
            'role' => ['prohibited'],
            'roles' => ['prohibited'],
            'is_active' => ['prohibited'],
            'must_change_password' => ['prohibited'],
        ];
    }
}
