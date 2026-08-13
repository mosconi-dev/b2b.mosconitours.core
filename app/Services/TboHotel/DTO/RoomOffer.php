<?php

namespace App\Services\TboHotel\DTO;

use App\Services\TboHotel\CancelPolicySet;
use App\Services\TboHotel\SupplementSet;
use Illuminate\Support\Arr;

/**
 * One bookable unit: the thing a BookingCode buys.
 *
 * On a multi-room search this is *all* the rooms together — TBO returns one code
 * and one total for the combination, with `names` holding one entry per room. It is
 * not one of these per room.
 */
readonly class RoomOffer
{
    /**
     * @param  array<int, string>  $names
     * @param  array<int, string>  $promotions
     * @param  array<int, float>  $dayRates
     */
    public function __construct(
        public string $bookingCode,
        public array $names,
        public ?string $inclusion,
        public float $totalFare,
        public float $totalTax,
        public string $mealType,
        public bool $isRefundable,
        public bool $withTransfers,
        public array $promotions,
        public array $dayRates,
        public CancelPolicySet $cancelPolicies,
        public SupplementSet $supplements,
    ) {}

    /**
     * Tidy one room name for display.
     *
     * TBO writes them without a space after the comma — "Standard Studio,1 Queen Bed" —
     * which reads as a typo of ours wherever it appears. Only whitespace is touched;
     * the words themselves are the supplier's and stay as they are.
     */
    private static function roomName(mixed $name): string
    {
        return trim(preg_replace('/\s*,\s*(?=\S)/u', ', ', (string) $name) ?? (string) $name);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromResponse(array $raw): self
    {
        return new self(
            bookingCode: (string) Arr::get($raw, 'BookingCode', ''),
            names: array_values(array_filter(array_map(
                self::roomName(...),
                (array) Arr::get($raw, 'Name', []),
            ))),
            inclusion: filled(Arr::get($raw, 'Inclusion')) ? trim((string) Arr::get($raw, 'Inclusion')) : null,
            totalFare: (float) Arr::get($raw, 'TotalFare', 0),
            totalTax: (float) Arr::get($raw, 'TotalTax', 0),
            mealType: (string) Arr::get($raw, 'MealType', ''),
            isRefundable: (bool) Arr::get($raw, 'IsRefundable', false),
            withTransfers: (bool) Arr::get($raw, 'WithTransfers', false),
            // One per room, and almost always the same words repeated. Three
            // identical "Book early and save" chips say nothing the first did not.
            promotions: array_values(array_unique(array_filter(array_map(
                fn ($p): string => trim((string) $p),
                (array) Arr::get($raw, 'RoomPromotion', []),
            )))),
            dayRates: self::dayRates(Arr::get($raw, 'DayRates')),
            cancelPolicies: CancelPolicySet::fromResponse(Arr::get($raw, 'CancelPolicies')),
            supplements: SupplementSet::fromResponse(Arr::get($raw, 'Supplements')),
        );
    }

    /**
     * The rooms this rate buys, collapsed to name and count.
     *
     * `names` holds one entry per physical room and they are usually identical, so a
     * three-room booking listed three identical lines — which reads as three different
     * rooms rather than three of one.
     *
     * @return array<int, array{name: string, count: int}>
     */
    public function rooms(): array
    {
        $counts = [];

        foreach ($this->names as $name) {
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }

        return array_map(
            fn (string $name, int $count): array => ['name' => $name, 'count' => $count],
            array_keys($counts),
            array_values($counts),
        );
    }

    /**
     * The inclusion, unless it is only repeating the meal plan.
     *
     * TBO sets Inclusion to "Room Only" on room-only rates, which rendered as
     * "Room only · Room Only".
     */
    public function inclusionLabel(): ?string
    {
        if ($this->inclusion === null) {
            return null;
        }

        return strcasecmp($this->inclusion, $this->mealLabel()) === 0 ? null : $this->inclusion;
    }

    /**
     * A guest-facing meal label. TBO's enum has ten values and the response
     * documentation lists three of them, so anything unrecognised is humanised
     * rather than matched.
     */
    public function mealLabel(): string
    {
        return match ($this->mealType) {
            'Room_Only' => 'Room only',
            'BreakFast', 'Breakfast_For_1', 'Breakfast_For_2' => 'Breakfast included',
            'BreakFast_Lunch' => 'Breakfast & lunch',
            'Half_Board' => 'Half board',
            'Full_Board' => 'Full board',
            'All_Inclusive_All_Meal' => 'All inclusive',
            default => ucfirst(str_replace('_', ' ', strtolower($this->mealType))),
        };
    }

    public function includesBreakfast(): bool
    {
        return ! in_array($this->mealType, ['Room_Only', 'Lunch', 'Dinner', ''], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'bookingCode' => $this->bookingCode,
            'names' => $this->names,
            'roomBreakdown' => $this->rooms(),
            'inclusion' => $this->inclusionLabel(),
            'totalFare' => $this->totalFare,
            'totalTax' => $this->totalTax,
            'mealType' => $this->mealType,
            'mealLabel' => $this->mealLabel(),
            'isRefundable' => $this->isRefundable,
            'withTransfers' => $this->withTransfers,
            'promotions' => $this->promotions,
            'dayRates' => $this->dayRates,
            'freeCancellationUntil' => $this->cancelPolicies->freeUntil(),
            'cancelPolicies' => $this->cancelPolicies->toArray(),
            // Flat and display-ready. cancelPolicies above is bucketed by room, which
            // is the wrong shape to iterate in a template — doing so renders nothing.
            'cancellationSchedule' => $this->cancelPolicies->schedule(),
            'nightlyRate' => $this->nightlyRate(),
            'supplements' => $this->supplements->toArray(),
            'payableAtProperty' => $this->supplements->payableAtPropertyGrouped(),
        ];
    }

    /**
     * What one room costs for one night, when every night costs the same.
     *
     * Null when they differ — a weekend rate averaged into a weekday one is a number
     * the agent would have to defend and could not.
     *
     * Derived from the total rather than taken from DayRates, even though DayRates
     * states it directly. TBO's figure excludes tax and the headline beside it does
     * not, so printing the two together invites the obvious multiplication and it does
     * not come out: 1,568.54 × 2 nights against a total of 12,108.06. DayRates is used
     * only to decide whether the nights are evenly priced, which is the one thing it
     * answers that the total cannot.
     */
    public function nightlyRate(): ?float
    {
        if ($this->dayRates === [] || count(array_unique($this->dayRates)) !== 1) {
            return null;
        }

        // DayRates carries one entry per room per night, so the room count divides out.
        $rooms = max(1, count($this->names));
        $nights = intdiv(count($this->dayRates), $rooms);

        return $nights < 1 ? null : round($this->totalFare / ($nights * $rooms), 2);
    }

    /**
     * DayRates arrives as a list of lists of `{ BasePrice }`, one entry per night.
     *
     * @return array<int, float>
     */
    private static function dayRates(mixed $raw): array
    {
        $rates = [];

        foreach ((array) $raw as $entry) {
            foreach ((array) $entry as $day) {
                $day = (array) $day;

                if (isset($day['BasePrice'])) {
                    $rates[] = (float) $day['BasePrice'];
                }
            }
        }

        return $rates;
    }
}
