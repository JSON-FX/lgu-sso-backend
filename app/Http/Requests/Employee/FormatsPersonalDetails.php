<?php

namespace App\Http\Requests\Employee;

use Illuminate\Support\Str;

trait FormatsPersonalDetails
{
    protected function prepareForValidation(): void
    {
        $formatted = [];
        foreach (['first_name', 'middle_name', 'last_name', 'nationality'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $formatted[$field] = preg_replace_callback(
                    "/(^|[\\s\\p{Pd}'’])(\\p{L})/u",
                    fn (array $match): string => $match[1].Str::upper($match[2]),
                    Str::lower(Str::squish($value)),
                );
            }
        }
        $this->merge($formatted);
    }
}
