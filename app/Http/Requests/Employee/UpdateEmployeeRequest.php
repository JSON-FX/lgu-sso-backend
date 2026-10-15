<?php

namespace App\Http\Requests\Employee;

use App\Enums\CivilStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $employee = $this->route('employee');

        return [
            'first_name' => ['sometimes', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
            'suffix' => ['nullable', 'string', 'max:50'],
            'birthday' => ['sometimes', 'date', 'before:today'],
            'civil_status' => ['sometimes', Rule::enum(CivilStatus::class)],
            'region' => ['sometimes', 'required', 'string', 'max:255'],
            'province' => ['sometimes', 'required', 'string', 'max:255'],
            'city' => ['sometimes', 'required', 'string', 'max:255'],
            'barangay' => ['sometimes', 'required', 'string', 'max:255'],
            'residence' => ['sometimes', 'nullable', 'string', 'max:500'],
            'block_number' => ['nullable', 'string', 'max:50'],
            'building_floor' => ['nullable', 'string', 'max:50'],
            'house_number' => ['nullable', 'string', 'max:50'],
            'nationality' => ['sometimes', 'string', 'max:100'],
            'email' => ['sometimes', 'email', Rule::unique('employees', 'email')->ignore($employee)],
            'password' => ['prohibited'],
            'must_change_password' => ['prohibited'],
            'is_active' => ['sometimes', 'boolean'],
            'office_id' => ['nullable', 'integer', 'exists:offices,id'],
            'position_id' => ['sometimes', 'nullable', 'integer', 'exists:positions,id'],
            'date_employed' => ['nullable', 'date'],
            'date_terminated' => ['nullable', 'date', 'after_or_equal:date_employed'],
        ];
    }
}
