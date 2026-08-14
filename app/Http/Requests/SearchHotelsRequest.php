<?php

namespace App\Http\Requests;

use App\Services\TboHotel\DTO\PaxRoom;
use App\Services\TboHotel\DTO\SearchInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

class SearchHotelsRequest extends FormRequest
{
    /** TBO prices per night; a stay longer than this is a series of bookings. */
    private const MAX_NIGHTS = 30;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'checkIn' => ['required', 'date', 'after_or_equal:today'],
            'checkOut' => ['required', 'date', 'after:checkIn'],
            'locationType' => ['required', 'in:city,hotel'],
            'locationCode' => ['required', 'string', 'max:32'],
            // Never defaulted. §18 is explicit that a wrong guest nationality is an
            // operational and financial problem TBO will not carry for us, and it
            // changes what a room costs.
            'guestNationality' => ['required', 'string', 'size:2'],
            'rooms' => ['required', 'array', 'min:1', 'max:6'],
            'rooms.*.adults' => ['required', 'integer', 'min:1', 'max:8'],
            'rooms.*.children' => ['required', 'integer', 'min:0', 'max:4'],
            'rooms.*.childrenAges' => ['array', 'max:4'],
            'rooms.*.childrenAges.*' => ['integer', 'min:0', 'max:18'],
            'refundableOnly' => ['boolean'],
            'mealType' => ['in:All,WithMeal,RoomOnly'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ((array) $this->input('rooms', []) as $i => $room) {
                $children = (int) ($room['children'] ?? 0);
                $ages = (array) ($room['childrenAges'] ?? []);

                // TBO requires one age per child, in the room that child sleeps in.
                // A mismatch is refused as an invalid request, so catch it here where
                // we can say which room.
                if (count($ages) !== $children) {
                    $validator->errors()->add(
                        "rooms.{$i}.childrenAges",
                        'Give an age for each child in room '.($i + 1).'.',
                    );
                }
            }

            if ($this->filled(['checkIn', 'checkOut'])) {
                $nights = Carbon::parse($this->input('checkIn'))->diffInDays(Carbon::parse($this->input('checkOut')));

                if ($nights > self::MAX_NIGHTS) {
                    $validator->errors()->add('checkOut', 'A stay cannot be longer than '.self::MAX_NIGHTS.' nights.');
                }
            }
        });
    }

    public function searchInput(): SearchInput
    {
        $validated = $this->validated();

        return new SearchInput(
            checkIn: Carbon::parse($validated['checkIn'])->toDateString(),
            checkOut: Carbon::parse($validated['checkOut'])->toDateString(),
            rooms: array_map(fn (array $room): PaxRoom => PaxRoom::fromArray($room), $validated['rooms']),
            guestNationality: strtoupper($validated['guestNationality']),
            locationType: $validated['locationType'],
            locationCode: $validated['locationCode'],
            refundableOnly: (bool) ($validated['refundableOnly'] ?? false),
            mealType: $validated['mealType'] ?? 'All',
        );
    }
}
