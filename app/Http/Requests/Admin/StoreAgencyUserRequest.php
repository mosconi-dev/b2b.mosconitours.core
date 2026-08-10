<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Creating a user from inside an agency. The agency comes from the route, never
 * from the payload — there is no agency_id field to forge.
 */
class StoreAgencyUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        // Both halves matter: the ability to create users at all, and the right to
        // touch this particular agency (platform staff: any; a member: only theirs).
        return (bool) $user?->can('user.create') && $user->can('view', $this->route('agency'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Uniqueness includes trashed rows to match the DB constraint.
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['integer', 'exists:roles,id'],
        ];
    }
}
