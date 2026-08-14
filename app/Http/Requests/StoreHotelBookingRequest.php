<?php

namespace App\Http\Requests;

use App\Services\TboHotel\DTO\Guest;
use App\Services\TboHotel\DTO\PaxRoom;
use App\Services\TboHotel\DTO\SearchInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreHotelBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route gates on hotel.book
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Text, not string: live codes run past 100 characters and are segmented.
            'bookingCode' => ['required', 'string', 'max:8192'],
            'checkIn' => ['required', 'date'],
            'checkOut' => ['required', 'date', 'after:checkIn'],
            'locationCode' => ['required', 'string', 'max:32'],
            'guestNationality' => ['required', 'string', 'size:2'],

            'rooms' => ['required', 'array', 'min:1', 'max:6'],
            'rooms.*.adults' => ['required', 'integer', 'min:1', 'max:8'],
            'rooms.*.children' => ['required', 'integer', 'min:0', 'max:4'],
            'rooms.*.childrenAges' => ['array', 'max:4'],
            'rooms.*.childrenAges.*' => ['integer', 'min:0', 'max:18'],

            'guests' => ['required', 'array', 'min:1', 'max:48'],
            'guests.*.title' => ['required', Rule::in(Guest::TITLES)],
            'guests.*.firstName' => ['required', 'string', 'max:64'],
            'guests.*.lastName' => ['required', 'string', 'max:64'],
            'guests.*.type' => ['required', Rule::in([Guest::ADULT, Guest::CHILD])],
            'guests.*.roomIndex' => ['required', 'integer', 'min:0', 'max:5'],
            'guests.*.isLead' => ['boolean'],

            'contact.email' => ['required', 'email', 'max:190'],
            'contact.phone' => ['required', 'string', 'max:32'],

            // What the agent was shown, so the service can gate a re-price. Advisory
            // only — the charge always comes from PreBook.
            'shownFare' => ['nullable', 'numeric', 'min:0'],
            'acceptPriceChange' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'guests.*.title.in' => 'TBO accepts Mr, Mrs or Ms only.',
            'guests.*.firstName.required' => 'Every guest needs a first name.',
            'guests.*.lastName.required' => 'Every guest needs a last name.',
        ];
    }

    /**
     * The stay, rebuilt server-side. Rooms come from the request because the guest
     * count has to be checked against the occupancy that was priced, and the priced
     * occupancy is not recoverable from the BookingCode alone.
     */
    public function searchInput(): SearchInput
    {
        $validated = $this->validated();

        return new SearchInput(
            checkIn: $validated['checkIn'],
            checkOut: $validated['checkOut'],
            rooms: array_map(fn (array $room): PaxRoom => PaxRoom::fromArray($room), $validated['rooms']),
            guestNationality: strtoupper($validated['guestNationality']),
            locationType: 'hotel',
            locationCode: $validated['locationCode'],
        );
    }

    /**
     * @return array<int, Guest>
     */
    public function guests(): array
    {
        return array_map(
            fn (array $guest): Guest => Guest::fromArray($guest),
            $this->validated()['guests'],
        );
    }

    /**
     * @return array{email: string, phone: string}
     */
    public function contact(): array
    {
        $contact = $this->validated()['contact'];

        return ['email' => $contact['email'], 'phone' => $contact['phone']];
    }

    public function shownFare(): ?float
    {
        $fare = $this->validated()['shownFare'] ?? null;

        return $fare === null ? null : (float) $fare;
    }

    public function acceptsPriceChange(): bool
    {
        return (bool) ($this->validated()['acceptPriceChange'] ?? false);
    }
}
