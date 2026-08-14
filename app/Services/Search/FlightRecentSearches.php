<?php

namespace App\Services\Search;

final class FlightRecentSearches extends RecentSearchStore
{
    /** Unchanged from when this was the only store, so no one loses their history. */
    protected function prefix(): string
    {
        return 'flight_recent';
    }
}
