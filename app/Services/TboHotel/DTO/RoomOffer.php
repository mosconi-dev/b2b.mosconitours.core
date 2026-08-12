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
     * @param  array<string, mixed>  $raw
     */
    public static function fromResponse(array $raw): self
    {
        return new self(
            bookingCode: (string) Arr::get($raw, 'BookingCode', ''),
            names: array_values(array_filter(array_map(
                fn ($n): string => trim((string) $n),
                (array) Arr::get($raw, 'Name', []),
            ))),
            inclusion: filled(Arr::get($raw, 'Inclusion')) ? trim((string) Arr::get($raw, 'Inclusion')) : null,
            totalFare: (float) Arr::get($raw, 'TotalFare', 0),
            totalTax: (float) Arr::get($raw, 'TotalTax', 0),
            mealType: (string) Arr::get($raw, 'MealType', ''),
            isRefundable: (bool) Arr::get($raw, 'IsRefundable', false),
            withTransfers: (bool) Arr::get($raw, 'WithTransfers', false),
            promotions: array_values(array_filter(array_map(
                fn ($p): string => trim((string) $p),
                (array) Arr::get($raw, 'RoomPromotion', []),
            ))),
            dayRates: self::dayRates(Arr::get($raw, 'DayRates')),
            cancelPolicies: CancelPolicySet::fromResponse(Arr::get($raw, 'CancelPolicies')),
            supplements: SupplementSet::fromResponse(Arr::get($raw, 'Supplements')),
        );
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
            'inclusion' => $this->inclusion,
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
            'supplements' => $this->supplements->toArray(),
            'payableAtProperty' => $this->supplements->payableAtProperty(),
        ];
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
