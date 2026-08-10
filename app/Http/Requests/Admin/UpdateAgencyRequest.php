<?php

namespace App\Http\Requests\Admin;

use App\Enums\AgencyType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The agency code is deliberately absent — it is immutable once issued.
 */
class UpdateAgencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('agency.update');
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('parent_id') === '') {
            $this->merge(['parent_id' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:128'],
            'type' => ['required', Rule::in(AgencyType::values())],
            'parent_id' => ['nullable', 'integer', 'exists:agencies,id'],
            'contact_email' => ['nullable', 'string', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:500'],
            // Ticked to clear the current logo without uploading a replacement.
            'remove_logo' => ['nullable', 'boolean'],
            ...StoreAgencyRequest::logoRules(),
        ];
    }
}
