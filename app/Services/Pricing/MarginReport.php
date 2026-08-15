<?php

namespace App\Services\Pricing;

use App\Models\BookingPriceLayer;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What each level actually earned.
 *
 * This is the report `booking_price_layers` exists for, and the reason it is a table
 * rather than a JSON blob on the booking: "how much margin did Agency X earn last
 * month?" is one indexed SUM here and a full scan with a decode there.
 *
 * Every figure comes from the layers, never from today's rules — a rule edited since
 * must not retroactively change what a past month earned.
 */
class MarginReport
{
    /**
     * Margin by agency for one level, newest month first.
     *
     * Scoped to the viewer: platform staff see every partner, anyone else sees only
     * their own agency. The scoping is here rather than in the caller because a margin
     * report is exactly the sort of screen where a missing `where` leaks a competitor's
     * numbers.
     *
     * @return Collection<int, object>
     */
    public function byAgency(User $viewer, int $level = BookingPriceLayer::AGENCY, int $months = 12): Collection
    {
        return BookingPriceLayer::query()
            // count(DISTINCT booking_id), not count(*): a level contributes one row per
            // matching rule now, so counting rows would report a booking priced by three
            // rules as three bookings.
            ->selectRaw('agency_id, count(distinct booking_id) as bookings, sum(markup_amount) as margin')
            ->where('level', $level)
            ->where('created_at', '>=', now()->subMonths($months)->startOfMonth())
            ->when(! $viewer->isPlatformStaff(), fn ($q) => $q->where('agency_id', $viewer->agency_id))
            ->groupBy('agency_id')
            ->orderByDesc('margin')
            ->with('agency:id,name,code')
            ->get()
            ->map(fn (BookingPriceLayer $row): object => (object) [
                'agency' => $row->agency,
                'bookings' => (int) $row->bookings,
                'margin' => Money::of($row->margin ?? 0),
            ]);
    }

    /**
     * One agency's own margin month by month, for the trend on its hub.
     *
     * @return Collection<int, object>
     */
    public function monthly(int $agencyId, int $level = BookingPriceLayer::AGENCY, int $months = 12): Collection
    {
        $query = BookingPriceLayer::query();

        // SQLite in tests, MySQL in dev and production. The date function is the one
        // thing that cannot be written once for both — so PICK one. selectRaw appends
        // rather than replaces, so adding the second on top of the first produced a
        // query carrying `strftime` AND `date_format`, which MySQL rejects outright.
        // Read from the connection's driver, not the connection's NAME: a connection
        // called something other than "sqlite" can still be one.
        $period = $query->getConnection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', created_at)"
            : "date_format(created_at, '%Y-%m')";

        return $query
            ->selectRaw("{$period} as period, count(distinct booking_id) as bookings, sum(markup_amount) as margin")
            ->where('agency_id', $agencyId)
            ->where('level', $level)
            ->where('created_at', '>=', now()->subMonths($months)->startOfMonth())
            ->groupBy('period')
            ->orderByDesc('period')
            ->get()
            ->map(fn ($row): object => (object) [
                'period' => $row->period,
                'bookings' => (int) $row->bookings,
                'margin' => Money::of($row->margin ?? 0),
            ]);
    }

    /**
     * The platform's own take across every partner — level 0, which is inside every
     * agency's cost.
     */
    public function platformTake(int $months = 12): Money
    {
        $total = BookingPriceLayer::where('level', BookingPriceLayer::MAIN_OFFICE)
            ->where('created_at', '>=', now()->subMonths($months)->startOfMonth())
            ->sum('markup_amount');

        return Money::of($total ?? 0);
    }
}
