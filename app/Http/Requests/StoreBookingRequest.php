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
    public function rules(): array
    {
        return [
            'traceId' => ['required', 'string', 'max:255'],
            'resultIndex' => ['required', 'string', 'max:8192'],

            'contact.email' => ['required', 'email', 'max:255'],
            'contact.phone' => ['required', 'string', 'max:32'],

            'passengers' => ['required', 'array', 'min:1', 'max:9'],
            'passengers.*.type' => ['required', Rule::in(['Adult', 'Child', 'Infant'])],
            'passengers.*.title' => ['required', 'string', 'max:8'],
            'passengers.*.firstName' => ['required', 'string', 'max:64'],
            'passengers.*.lastName' => ['required', 'string', 'max:64'],
            'passengers.*.gender' => ['nullable', Rule::in(['M', 'F'])],
            'passengers.*.dateOfBirth' => ['nullable', 'date'],
            // Passport is optional structurally; BookingService enforces it against the
            // fresh FareQuote when the fare requires it.
            'passengers.*.passportNo' => ['nullable', 'string', 'max:32'],
            'passengers.*.passportExpiry' => ['nullable', 'date'],
            'passengers.*.nationality' => ['nullable', 'string', 'max:2'],
            // Selected SSR option codes (LCC ancillaries); priced authoritatively server-side.
            'passengers.*.baggage' => ['nullable', 'string', 'max:32'],
            'passengers.*.meal' => ['nullable', 'string', 'max:32'],
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
}
