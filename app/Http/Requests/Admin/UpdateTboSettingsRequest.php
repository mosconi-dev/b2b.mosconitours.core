<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTboSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('supplier.tbo.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'environment' => ['required', Rule::in(['test', 'live'])],
            'cache_key' => ['required', 'string', 'max:128'],
            // Floor avoids hammering TBO auth; ceiling stays within the ~24h token validity.
            'ttl_test' => ['required', 'integer', 'min:60', 'max:86400'],
            'ttl_live' => ['required', 'integer', 'min:60', 'max:86400'],
        ];
    }
}
