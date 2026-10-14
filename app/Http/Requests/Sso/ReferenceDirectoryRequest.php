<?php

namespace App\Http\Requests\Sso;

use Illuminate\Foundation\Http\FormRequest;

class ReferenceDirectoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'between:1,10000'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }

    public function messages(): array
    {
        return [
            'search.max' => 'Search must not exceed 100 characters.',
            'page.between' => 'Page must be between 1 and 10000.',
            'per_page.between' => 'Page size must be between 1 and 100.',
        ];
    }
}
