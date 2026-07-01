<?php

namespace App\Http\Requests;

use App\Enums\CabinClass;
use App\Enums\TripType;
use App\Services\TboAir\DTO\SearchInput;
use App\Support\Airports;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SearchFlightsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the front-end payload: extract IATA codes from "City (XXX)"
     * inputs and rename `dest` -> `destination` before validation.
     */
    protected function prepareForValidation(): void
    {
        $segments = collect($this->input('segments', []))
            ->map(fn ($s) => [
                'origin' => Airports::extractCode($s['origin'] ?? null),
                'destination' => Airports::extractCode($s['dest'] ?? $s['destination'] ?? null),
                'departure' => $s['departure'] ?? null,
            ])
            ->all();

        $this->merge(['segments' => $segments]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tripType' => ['required', Rule::enum(TripType::class)],
            'cabin' => ['required', Rule::enum(CabinClass::class)],
            'adults' => ['required', 'integer', 'min:1', 'max:9'],
            'children' => ['required', 'integer', 'min:0', 'max:8'],
            'infants' => ['required', 'integer', 'min:0', 'lte:adults'],
            'segments' => ['required', 'array', 'min:1', 'max:6'],
            'segments.*.origin' => ['required', 'string', Rule::in(Airports::codes())],
            'segments.*.destination' => ['required', 'string', Rule::in(Airports::codes())],
            'segments.*.departure' => ['required', 'date', 'after_or_equal:today'],
            'returnDate' => ['nullable', 'required_if:tripType,round', 'date', 'after_or_equal:segments.0.departure'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ((array) $this->input('segments', []) as $i => $segment) {
                if (! empty($segment['origin']) && $segment['origin'] === ($segment['destination'] ?? null)) {
                    $validator->errors()->add("segments.$i.destination", 'Origin and destination must be different.');
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'segments.*.origin.required' => 'Origin is required.',
            'segments.*.origin.in' => 'Please choose a valid origin airport.',
            'segments.*.destination.required' => 'Destination is required.',
            'segments.*.destination.in' => 'Please choose a valid destination airport.',
            'segments.*.departure.required' => 'Departure date is required.',
        ];
    }

    public function searchInput(): SearchInput
    {
        $validated = $this->validated();

        return new SearchInput(
            tripType: TripType::from($validated['tripType']),
            cabin: CabinClass::from($validated['cabin']),
            adults: (int) $validated['adults'],
            children: (int) $validated['children'],
            infants: (int) $validated['infants'],
            segments: array_map(fn (array $s): array => [
                'origin' => $s['origin'],
                'destination' => $s['destination'],
                'departure' => $s['departure'],
            ], $validated['segments']),
            returnDate: $validated['returnDate'] ?? null,
        );
    }
}
