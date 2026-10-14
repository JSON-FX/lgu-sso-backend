<?php

namespace App\Http\Requests\Office;

use App\Models\Office;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveOfficeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => $this->textRules('name', 255),
            'abbreviation' => $this->textRules('abbreviation', 20),
            'is_active' => $this->isMethod('post') ? ['prohibited'] : ['sometimes', 'required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'abbreviation'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    private function textRules(string $field, int $maximum): array
    {
        $office = $this->route('office');

        return [
            ...($this->isMethod('post') ? [] : ['sometimes']),
            'bail', 'required', 'string', 'max:'.$maximum,
            Rule::unique('offices', $field)->ignore($office instanceof Office ? $office->id : null),
            function (string $attribute, mixed $value, Closure $fail) use ($office, $field): void {
                if (Office::query()
                    ->when($office instanceof Office, fn ($query) => $query->whereKeyNot($office->id))
                    ->whereRaw("LOWER({$field}) = ?", [mb_strtolower($value)])
                    ->exists()) {
                    $fail('An office with this '.$attribute.' already exists.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Enter an office name.',
            'name.max' => 'Office names must be 255 characters or fewer.',
            'name.unique' => 'An office with this name already exists.',
            'abbreviation.required' => 'Enter an office abbreviation.',
            'abbreviation.max' => 'Abbreviations must be 20 characters or fewer.',
            'abbreviation.unique' => 'An office with this abbreviation already exists.',
            'is_active.prohibited' => 'New offices are active by default.',
            'is_active.boolean' => 'Office status must be a boolean.',
        ];
    }
}
