<?php

namespace App\Http\Requests\Position;

use App\Models\Position;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $position = $this->route('position');

        return [
            'title' => [
                ...($this->isMethod('post') ? [] : ['sometimes']),
                'bail', 'required', 'string', 'max:255',
                Rule::unique('positions', 'title')->ignore($position instanceof Position ? $position->id : null),
                function (string $attribute, mixed $value, Closure $fail) use ($position): void {
                    if (Position::query()
                        ->when($position instanceof Position, fn ($query) => $query->whereKeyNot($position->id))
                        ->whereRaw('LOWER(title) = ?', [mb_strtolower($value)])
                        ->exists()) {
                        $fail('A position with this title already exists.');
                    }
                },
            ],
            'is_active' => $this->isMethod('post') ? ['prohibited'] : ['sometimes', 'required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('title'))) {
            $this->merge(['title' => trim($this->input('title'))]);
        }
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Enter a position title.',
            'title.max' => 'Position titles must be 255 characters or fewer.',
            'title.unique' => 'A position with this title already exists.',
            'is_active.prohibited' => 'New positions are active by default.',
            'is_active.boolean' => 'Position status must be a boolean.',
        ];
    }
}
