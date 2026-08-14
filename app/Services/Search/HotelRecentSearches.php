<?php

namespace App\Services\Search;

final class HotelRecentSearches extends RecentSearchStore
{
    protected function prefix(): string
    {
        return 'hotel_recent';
    }
}
