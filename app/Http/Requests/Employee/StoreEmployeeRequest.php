<?php

namespace App\Http\Requests\Employee;

use App\Enums\CivilStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'suffix' => ['nullable', 'string', 'max:50'],
            'birthday' => ['required', 'date', 'before:today'],
            'civil_status' => ['required', Rule::enum(CivilStatus::class)],
            'region' => ['nullable', 'string', 'max:255'],
            'province' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'barangay' => ['nullable', 'string', 'max:255'],
            'residence' => ['required', 'string', 'max:500'],
            'block_number' => ['nullable', 'string', 'max:50'],
            'building_floor' => ['nullable', 'string', 'max:50'],
            'house_number' => ['nullable', 'string', 'max:50'],
            'nationality' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'unique:employees,email'],
            'office_id' => ['nullable', 'integer', 'exists:offices,id'],
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],
            'date_employed' => ['nullable', 'date'],
            'date_terminated' => ['nullable', 'date', 'after_or_equal:date_employed'],
        ];
    }
}
