<?php

namespace App\Http\Requests;

use App\Enums\CabinClass;
use App\Enums\TripType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the client-shaped recent-search list before it is cached. The list
 * is display-only (a shortcut back into the form), so the rules just keep the
 * structure sane and bounded — the real search is re-validated on submit.
 */
class StoreRecentSearchesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('flight.view') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'recent' => ['present', 'array', 'max:6'],
            'recent.*.id' => ['required', 'string', 'max:255'],
            'recent.*.tripType' => ['required', Rule::enum(TripType::class)],
            'recent.*.cabin' => ['required', Rule::enum(CabinClass::class)],
            'recent.*.pax' => ['required', 'array'],
            'recent.*.pax.adults' => ['required', 'integer', 'min:0', 'max:9'],
            'recent.*.pax.children' => ['required', 'integer', 'min:0', 'max:9'],
            'recent.*.pax.infants' => ['required', 'integer', 'min:0', 'max:9'],
            'recent.*.segments' => ['required', 'array', 'min:1', 'max:6'],
            'recent.*.segments.*.origin' => ['nullable', 'string', 'max:120'],
            'recent.*.segments.*.dest' => ['nullable', 'string', 'max:120'],
            'recent.*.segments.*.departure' => ['nullable', 'string', 'max:40'],
            'recent.*.returnDate' => ['nullable', 'string', 'max:40'],
            'recent.*.routeText' => ['nullable', 'string', 'max:200'],
            'recent.*.dateText' => ['nullable', 'string', 'max:80'],
            'recent.*.metaText' => ['nullable', 'string', 'max:80'],
        ];
    }
}
