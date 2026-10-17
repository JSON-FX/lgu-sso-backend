<?php

namespace App\Http\Requests\Sso;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AuthorizeBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'list', 'min:1', 'max:10'],
            'items.*' => ['required', 'array:id,token', 'required_array_keys:id,token'],
            'items.*.id' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{1,64}\z/', 'distinct:strict'],
            'items.*.token' => ['required', 'string', 'distinct:strict', function ($attribute, $value, $fail) {
                if (! is_string($value) || strlen($value) > 16384 || preg_match('/[\s\p{C}]/u', $value)) {
                    $fail('Invalid authorization token.');
                }
            }],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if (array_keys($this->all()) !== ['items']) {
                $validator->errors()->add('items', 'Invalid authorization batch.');
            }
        }];
    }
}
