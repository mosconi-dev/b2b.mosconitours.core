<?php

namespace App\Http\Requests;

use App\Services\Booking\DTO\Passenger;
use App\Services\TboAir\DTO\SelectionInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route-level `can:booking.create` gates authorization.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Widen a single SSR code into a one-element list.
     *
     * Add-ons became per-leg because TBO prices them per segment, but an older client
     * — or a saved payload — still sends one code. Normalising here keeps the rules
     * to a single shape instead of every downstream reader guessing.
     */
    protected function prepareForValidation(): void
    {
        $passengers = $this->input('passengers');

        if (! is_array($passengers)) {
            return;
        }

        foreach ($passengers as $i => $passenger) {
            foreach (['baggage', 'meal'] as $kind) {
                $value = $passenger[$kind] ?? null;

                if (is_string($value)) {
                    $passengers[$i][$kind] = $value === '' ? [] : [$value];
                }
            }
        }

        $this->merge(['passengers' => $passengers]);
    }

    public function rules(): array
    {
        return [
            'traceId' => ['required', 'string', 'max:255'],
            'resultIndex' => ['required', 'string', 'max:8192'],

            // Collected once and fanned onto every passenger — TBO's Book method wants
            // an address, mobile and email on each one. See Passenger.
            'contact.email' => ['required', 'email', 'max:255'],
            'contact.phone' => ['required', 'string', 'max:32'],
            'contact.mobileCountryCode' => ['required', 'string', 'max:5'],
            'contact.addressLine1' => ['required', 'string', 'max:128'],
            'contact.addressLine2' => ['nullable', 'string', 'max:128'],
            'contact.city' => ['required', 'string', 'max:64'],
            'contact.countryCode' => ['required', 'string', 'size:2', 'alpha'],

            // Per-segment seat availability captured at search time; FareQuote drops it
            // and Book needs it back. Nullable entries mean "not captured", which is a
            // different fact from zero seats.
            'seats' => ['nullable', 'array', 'max:32'],
            'seats.*' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'resultType' => ['nullable', 'integer', 'min:0', 'max:99'],

            'passengers' => ['required', 'array', 'min:1', 'max:9'],
            'passengers.*.type' => ['required', Rule::in(['Adult', 'Child', 'Infant'])],
            // TBO's title enum has exactly three values (Mr=0, Miss=1, Mrs=2), so the
            // wizard offers only those — see TboPassengerMapper.
            'passengers.*.title' => ['required', Rule::in(['Mr', 'Mrs', 'Miss'])],
            'passengers.*.isLeadPax' => ['nullable', 'boolean'],
            'passengers.*.firstName' => ['required', 'string', 'max:64'],
            'passengers.*.lastName' => ['required', 'string', 'max:64'],
            'passengers.*.gender' => ['nullable', Rule::in(['M', 'F'])],
            // TBO refuses a null date of birth at Ticket — "Invalid Date of Birth of
            // Adult", Code 3 — which is after the money is committed. Required here.
            // The age band is checked against the departure date in BookingService.
            'passengers.*.dateOfBirth' => ['required', 'date', 'before:today'],
            // The identity document: a passport internationally, any government ID
            // domestically. Optional structurally; BookingService enforces it against
            // the fresh FareQuote, which knows both the route and TBO's flags.
            'passengers.*.documentNumber' => ['nullable', 'string', 'max:32'],
            'passengers.*.documentExpiry' => ['nullable', 'date'],
            'passengers.*.documentIssueCountry' => ['nullable', 'string', 'size:2', 'alpha'],
            'passengers.*.documentIssueDate' => ['nullable', 'date'],
            'passengers.*.nationality' => ['nullable', 'string', 'max:2'],
            // Selected SSR option codes (LCC ancillaries); priced authoritatively server-side.
            // One selection per leg: TBO prices add-ons per segment. A bare string
            // (the pre-per-leg shape) is widened to a list in prepareForValidation.
            'passengers.*.baggage' => ['nullable', 'array'],
            'passengers.*.baggage.*' => ['string', 'max:64'],
            'passengers.*.meal' => ['nullable', 'array'],
            'passengers.*.meal.*' => ['string', 'max:64'],
        ];
    }

    public function selection(): SelectionInput
    {
        return new SelectionInput(
            (string) $this->validated('traceId'),
            (string) $this->validated('resultIndex'),
        );
    }

    /**
     * @return array<int, Passenger>
     */
    public function passengers(): array
    {
        return array_map(
            fn (array $p): Passenger => Passenger::fromArray($p),
            $this->validated('passengers'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function contact(): array
    {
        return $this->validated('contact');
    }

    /**
     * @return array<int, int|null>
     */
    public function seats(): array
    {
        return array_values((array) ($this->validated('seats') ?? []));
    }

    public function resultType(): ?int
    {
        $value = $this->validated('resultType');

        return $value === null ? null : (int) $value;
    }
}
