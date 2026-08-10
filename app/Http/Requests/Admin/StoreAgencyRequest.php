<?php

namespace App\Http\Requests\Admin;

use App\Enums\AgencyType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAgencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('agency.create');
    }

    protected function prepareForValidation(): void
    {
        // Blank code means "derive one from the name".
        if ($this->input('code') === '') {
            $this->merge(['code' => null]);
        }

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
            // Uniqueness includes trashed rows to match the DB constraint.
            'code' => ['nullable', 'string', 'max:32', 'unique:agencies,code'],
            'type' => ['required', Rule::in(AgencyType::values())],
            'parent_id' => ['nullable', 'integer', 'exists:agencies,id'],
            'contact_email' => ['nullable', 'string', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:500'],
            ...self::logoRules(),
        ];
    }

    /**
     * Shared with UpdateAgencyRequest.
     *
     * SVG is deliberately excluded: it is a document that can carry script, and these
     * files are served from our own origin. Raster formats only.
     *
     * @return array<string, mixed>
     */
    public static function logoRules(): array
    {
        return [
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=4000,max_height=4000'],
        ];
    }
}
