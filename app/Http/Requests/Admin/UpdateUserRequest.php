<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Policy, not the bare permission: it adds the same-agency scope check.
        return (bool) $this->user()?->can('update', $this->route('user'));
    }

    protected function prepareForValidation(): void
    {
        // An empty select means "no override".
        if ($this->input('tbo_environment') === '') {
            $this->merge(['tbo_environment' => null]);
        }

        // An empty select means "platform staff".
        if ($this->input('agency_id') === '') {
            $this->merge(['agency_id' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($this->route('user')),
            ],
            'agency_id' => ['nullable', 'integer', 'exists:agencies,id'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['integer', 'exists:roles,id'],
            'tbo_environment' => ['nullable', Rule::in(['test', 'live'])],
        ];
    }
}
