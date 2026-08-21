<?php

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\HotelBooking;
use App\Services\Booking\Concerns\IssuedByAgency;
use Illuminate\Support\Carbon;

/**
 * Everything a printed hotel voucher shows, assembled from one booking.
 *
 * The hotel counterpart of {@see ETicket}, and deliberately its sibling rather than its
 * cousin: same masthead, same reference band, same section grammar, same guest copy.
 * An agency hands out both documents and they should look like they came from the same
 * desk.
 *
 * Built entirely from `hotel_bookings` and the stored PreBook quote. Nothing here calls
 * TBO — a guest standing at a front desk must not depend on the supplier being
 * reachable, and a voucher read eighteen months later must say what it said the day it
 * was issued.
 */
class HotelVoucher
{
    use IssuedByAgency;

    private function __construct(
        public readonly Booking $booking,
        public readonly HotelBooking $stay,
        public readonly bool $withPrices,
    ) {}

    public static function for(Booking $booking, bool $withPrices = true): self
    {
        return new self($booking, $booking->hotel, $withPrices);
    }

    public function title(): string
    {
        return 'Hotel Voucher';
    }

    /**
     * The one-line warning printed under the heading.
     *
     * A voucher is a document people keep, and the copy in a guest's hand does not
     * update when the booking does. So a stay that is no longer standing has to say so
     * on its face — the alternative is someone arriving at a property that is not
     * holding a room for them.
     */
    public function notice(): ?string
    {
        return match ($this->booking->status) {
            BookingStatus::Cancelled, BookingStatus::Refunded => 'This booking has been cancelled. This voucher is no longer valid and the property is not holding a room.',
            BookingStatus::Cancelling => 'A cancellation has been requested for this booking and is being processed. Do not travel on this voucher without contacting us first.',
            default => null,
        };
    }

    /**
     * The property, and the stay itself.
     *
     * @return array<string, mixed>
     */
    public function property(): array
    {
        return [
            'name' => (string) $this->stay->hotel_name,
            'rating' => (int) ($this->stay->rating ?? 0),
            'address' => collect([$this->stay->address, $this->stay->city])
                ->filter()
                ->unique()
                ->implode(', ') ?: null,
            'code' => (string) $this->stay->hotel_code,
        ];
    }

    public function checkIn(): Carbon
    {
        return $this->stay->check_in;
    }

    public function checkOut(): Carbon
    {
        return $this->stay->check_out;
    }

    /**
     * The desk hours, lifted out of the hotel's own conditions.
     *
     * TBO states these as plain lines inside `RateConditions` — "CheckIn Time-Begin:
     * 2:00 PM" — where they are buried in a wall of small print, and they are the first
     * thing a guest arriving at 8am needs to know. Read conservatively: an exact label
     * or nothing, because a wrong arrival time is worse than a missing one.
     *
     * @return array{from: ?string, until: ?string, out: ?string}
     */
    public function deskHours(): array
    {
        $text = strip_tags(implode("\n", $this->stay->rate_conditions ?? []));

        $match = function (string $label) use ($text): ?string {
            $pattern = '/'.$label.'\s*:?\s*([0-9]{1,2}:[0-9]{2}\s*(?:AM|PM)?)/i';

            return preg_match($pattern, $text, $m) === 1 ? trim($m[1]) : null;
        };

        return [
            'from' => $match('Check\s*-?\s*In\s*Time\s*-\s*Begin'),
            'until' => $match('Check\s*-?\s*In\s*Time\s*-\s*End'),
            'out' => $match('Check\s*-?\s*Out\s*Time'),
        ];
    }

    public function mealPlan(): string
    {
        $stored = (string) data_get($this->booking->quote, 'mealLabel', '');

        if ($stored !== '') {
            return $stored;
        }

        $type = (string) $this->stay->meal_type;

        return $type === '' ? 'Room only' : ucfirst(str_replace('_', ' ', strtolower($type)));
    }

    /**
     * One row per room: what it is, and who sleeps in it.
     *
     * Guests are grouped by the room index they were booked under rather than listed
     * flat, because that grouping is what TBO was sent and what the property will have
     * on its rooming list.
     *
     * @return array<int, array{index: int, name: ?string, guests: array<int, array{name: string, type: string, isLead: bool}>}>
     */
    public function rooms(): array
    {
        $names = $this->stay->room_names ?? [];
        $grouped = [];

        foreach ($this->booking->pax ?? [] as $guest) {
            $index = (int) ($guest['roomIndex'] ?? 0);

            $grouped[$index][] = [
                'name' => trim(sprintf(
                    '%s %s %s',
                    $guest['title'] ?? '',
                    $guest['firstName'] ?? '',
                    $guest['lastName'] ?? '',
                )),
                'type' => (string) ($guest['type'] ?? 'Adult'),
                'isLead' => (bool) ($guest['isLead'] ?? false),
            ];
        }

        // A room with no names still belongs on the voucher — the property is holding
        // it either way, and a missing row reads as a room we did not book.
        for ($index = 0; $index < max(1, (int) $this->stay->rooms_count); $index++) {
            $grouped[$index] ??= [];
        }

        ksort($grouped);

        return array_map(fn (int $index): array => [
            'index' => $index,
            'name' => $names[$index] ?? ($names[0] ?? null),
            'guests' => $grouped[$index],
        ], array_keys($grouped));
    }

    /**
     * What the guest still settles at the property, grouped across rooms.
     *
     * @return array<int, array<string, mixed>>
     */
    public function payableAtProperty(): array
    {
        return $this->stay->payableAtProperty();
    }

    public function payableAtPropertyTotal(): float
    {
        return round(array_sum(array_column($this->payableAtProperty(), 'total')), 2);
    }

    /**
     * The cancellation ladder, as PreBook stated it and §18 made binding.
     *
     * @return array<int, array{room: ?int, from: Carbon, chargeType: string, charge: float}>
     */
    public function cancellation(): array
    {
        return array_values(array_filter(array_map(function (array $policy): ?array {
            if (blank($policy['from'] ?? null)) {
                return null;
            }

            return [
                'room' => $policy['room'] ?? null,
                'from' => Carbon::parse($policy['from']),
                'chargeType' => (string) ($policy['chargeType'] ?? ''),
                'charge' => (float) ($policy['charge'] ?? 0),
            ];
        }, (array) data_get($this->booking->quote, 'cancellationSchedule', []))));
    }

    /**
     * The hotel's own norms, already reduced to safe markup on the way in.
     *
     * @return array<int, string>
     */
    public function conditions(): array
    {
        return array_values(array_filter($this->stay->rate_conditions ?? []));
    }

    /**
     * The money, in the shape the fare summary uses.
     *
     * One BookingCode is one combined total for the whole stay, so there is no honest
     * per-room split to print — the accommodation line names what the total covers
     * instead, and the tax line only appears when TBO actually charged one.
     *
     * @return array{nights: int, rooms: int, accommodation: float, tax: float, nightly: ?float, total: float}
     */
    public function charges(): array
    {
        $total = (float) $this->booking->total_amount;
        $tax = (float) data_get($this->booking->quote, 'totalTax', 0);

        return [
            'nights' => (int) $this->stay->nights,
            'rooms' => (int) $this->stay->rooms_count,
            'accommodation' => round($total - $tax, 2),
            'tax' => round($tax, 2),
            // Recomputed from the SELLING total, never read from the stored quote —
            // that figure is the supplier's own per-night net, and multiplying it back
            // up by nights and rooms hands an agency our cost. The supplier's null is
            // kept: it means the nights were priced unevenly, and an averaged rate is a
            // number the agent would have to defend and could not.
            'nightly' => data_get($this->booking->quote, 'nightlyRate') !== null
                ? round($total / max(1, $this->stay->nights * $this->stay->rooms_count), 2)
                : null,
            'total' => $total,
        ];
    }

    /**
     * Anything the rate itself came with — breakfast, transfers, a promotion.
     *
     * @return array<int, string>
     */
    public function inclusions(): array
    {
        $rows = [$this->mealPlan()];

        if ($this->stay->with_transfers) {
            $rows[] = 'Transfers included';
        }

        foreach ((array) data_get($this->booking->quote, 'promotions', []) as $promotion) {
            if (filled($promotion)) {
                $rows[] = (string) $promotion;
            }
        }

        return array_values(array_unique($rows));
    }

    /**
     * Who the booking was made for, for the "need help" panel.
     *
     * @return array{name: string, email: ?string, phone: ?string}
     */
    public function contact(): array
    {
        $contact = $this->booking->contact ?? [];
        $lead = collect($this->booking->pax ?? [])->firstWhere('isLead', true)
            ?? ($this->booking->pax[0] ?? []);

        return [
            'name' => trim(sprintf('%s %s', $lead['firstName'] ?? '', $lead['lastName'] ?? '')),
            'email' => $contact['email'] ?? null,
            'phone' => $contact['phone'] ?? null,
        ];
    }
}
