<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('role.create');
    }

    protected function prepareForValidation(): void
    {
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
            'name' => ['required', 'string', 'max:128'],
            'description' => ['nullable', 'string', 'max:255'],
            // Honoured only for platform staff; RoleService forces an agency member's
            // own agency regardless of what is submitted.
            'agency_id' => ['nullable', 'integer', 'exists:agencies,id'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ];
    }
}
