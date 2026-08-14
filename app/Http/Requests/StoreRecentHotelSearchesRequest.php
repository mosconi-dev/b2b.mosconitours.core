<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the client-shaped recent-search list before it is cached. The list is
 * display-only (a shortcut back into the form), so the rules just keep the structure
 * sane and bounded — the real search is re-validated on submit by SearchHotelsRequest.
 */
class StoreRecentHotelSearchesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('hotel.view') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'recent' => ['present', 'array', 'max:6'],
            'recent.*.id' => ['required', 'string', 'max:255'],
            'recent.*.locationType' => ['required', 'in:city,hotel'],
            'recent.*.locationCode' => ['required', 'string', 'max:32'],
            // The destination as the agent picked it off the suggest list. It is both
            // the entry's headline and what the form shows again when it is applied,
            // so unlike the flight list's display strings it cannot be null.
            'recent.*.locationLabel' => ['required', 'string', 'max:120'],
            // Deliberately not after_or_equal:today — see HotelController::upcoming().
            // A list being re-saved may carry a stay that went stale since it was
            // written, and that is no reason to refuse the five entries still good.
            'recent.*.checkIn' => ['required', 'date'],
            'recent.*.checkOut' => ['required', 'date', 'after:recent.*.checkIn'],
            'recent.*.guestNationality' => ['required', 'string', 'size:2'],
            // The same occupancy token the rooms page already takes ("2-0;2-1x8,10"),
            // so one encoding serves the URL, the shortcut, and the decoders on both
            // sides rather than inventing a second shape for the same four facts.
            'recent.*.rooms' => ['required', 'string', 'max:255'],
            'recent.*.refundableOnly' => ['boolean'],
            'recent.*.dateText' => ['nullable', 'string', 'max:80'],
            'recent.*.metaText' => ['nullable', 'string', 'max:80'],
        ];
    }
}
