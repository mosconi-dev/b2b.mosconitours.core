<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Search\FlightRecentSearches;
use App\Services\Search\HotelRecentSearches;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class HotelRecentSearchesTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function hotelUser(): User
    {
        return $this->userWith(['hotel.view', 'hotel.search']);
    }

    /**
     * Dates are relative so the fixture cannot rot into the past and start failing
     * the very filter these tests exist to check.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sampleRecent(int $checkInDays = 30): array
    {
        $checkIn = Carbon::today()->addDays($checkInDays)->toDateString();
        $checkOut = Carbon::today()->addDays($checkInDays + 2)->toDateString();

        return [[
            'id' => "city~130443~{$checkIn}~{$checkOut}~PH~2-0~0",
            'locationType' => 'city',
            'locationCode' => '130443',
            'locationLabel' => 'Cebu, Philippines',
            'checkIn' => $checkIn,
            'checkOut' => $checkOut,
            'guestNationality' => 'PH',
            'rooms' => '2-0',
            'refundableOnly' => false,
            'dateText' => '2 nights',
            'metaText' => '1 room, 2 guests · PH',
        ]];
    }

    public function test_recent_searches_are_stored_in_the_per_user_cache(): void
    {
        $user = $this->hotelUser();

        $this->actingAs($user)
            ->postJson(route('hotels.recent'), ['recent' => $this->sampleRecent()])
            ->assertNoContent();

        $stored = app(HotelRecentSearches::class)->get($user->id);

        $this->assertCount(1, $stored);
        $this->assertSame('Cebu, Philippines', $stored[0]['locationLabel']);
        $this->assertSame('2-0', $stored[0]['rooms']);
    }

    public function test_cached_recent_searches_are_handed_to_the_hotels_page(): void
    {
        $user = $this->hotelUser();
        app(HotelRecentSearches::class)->put($user->id, $this->sampleRecent());

        $this->actingAs($user)
            ->get(route('hotels'))
            ->assertOk()
            ->assertViewHas('recent', fn (array $recent): bool => count($recent) === 1
                && $recent[0]['locationLabel'] === 'Cebu, Philippines');
    }

    public function test_stays_whose_check_in_has_passed_are_left_off_the_page(): void
    {
        $user = $this->hotelUser();
        app(HotelRecentSearches::class)->put($user->id, array_merge(
            $this->sampleRecent(-3),
            $this->sampleRecent(30),
        ));

        $this->actingAs($user)
            ->get(route('hotels'))
            ->assertOk()
            // The stale one is filtered on the way out, not deleted: the cached list
            // still holds both, and re-keying it is the client's job.
            ->assertViewHas('recent', fn (array $recent): bool => count($recent) === 1
                && $recent[0]['checkIn'] === Carbon::today()->addDays(30)->toDateString());

        $this->assertCount(2, app(HotelRecentSearches::class)->get($user->id));
    }

    public function test_recent_searches_are_scoped_per_user(): void
    {
        $owner = $this->hotelUser();
        $other = $this->hotelUser();
        app(HotelRecentSearches::class)->put($owner->id, $this->sampleRecent());

        $this->assertCount(1, app(HotelRecentSearches::class)->get($owner->id));
        $this->assertCount(0, app(HotelRecentSearches::class)->get($other->id));
    }

    /**
     * The point of the per-product subclasses: one user, two histories, no collision.
     */
    public function test_the_flight_and_hotel_histories_do_not_collide(): void
    {
        $user = $this->hotelUser();

        app(HotelRecentSearches::class)->put($user->id, $this->sampleRecent());
        app(FlightRecentSearches::class)->put($user->id, [['id' => 'a-flight']]);

        $this->assertSame('Cebu, Philippines', app(HotelRecentSearches::class)->get($user->id)[0]['locationLabel']);
        $this->assertSame('a-flight', app(FlightRecentSearches::class)->get($user->id)[0]['id']);
    }

    public function test_storing_recent_searches_requires_hotel_view_permission(): void
    {
        $user = $this->userWith([]); // no permissions

        $this->actingAs($user)
            ->postJson(route('hotels.recent'), ['recent' => $this->sampleRecent()])
            ->assertForbidden();
    }

    public function test_an_empty_list_clears_the_cached_history(): void
    {
        $user = $this->hotelUser();
        app(HotelRecentSearches::class)->put($user->id, $this->sampleRecent());

        $this->actingAs($user)
            ->postJson(route('hotels.recent'), ['recent' => []])
            ->assertNoContent();

        $this->assertCount(0, app(HotelRecentSearches::class)->get($user->id));
    }

    public function test_the_list_is_bounded_to_six_entries(): void
    {
        $user = $this->hotelUser();
        $entry = $this->sampleRecent()[0];
        $tooMany = [];
        for ($i = 0; $i < 7; $i++) {
            $tooMany[] = array_merge($entry, ['id' => "entry-$i"]);
        }

        $this->actingAs($user)
            ->postJson(route('hotels.recent'), ['recent' => $tooMany])
            ->assertStatus(422)
            ->assertJsonValidationErrors('recent');
    }

    public function test_an_invalid_location_type_is_rejected(): void
    {
        $user = $this->hotelUser();
        $bad = $this->sampleRecent();
        $bad[0]['locationType'] = 'continent';

        $this->actingAs($user)
            ->postJson(route('hotels.recent'), ['recent' => $bad])
            ->assertStatus(422)
            ->assertJsonValidationErrors('recent.0.locationType');
    }

    public function test_a_stay_that_ends_before_it_starts_is_rejected(): void
    {
        $user = $this->hotelUser();
        $bad = $this->sampleRecent();
        $bad[0]['checkOut'] = $bad[0]['checkIn'];

        $this->actingAs($user)
            ->postJson(route('hotels.recent'), ['recent' => $bad])
            ->assertStatus(422)
            ->assertJsonValidationErrors('recent.0.checkOut');
    }

    /**
     * A list is written as a whole, so one entry that aged out since it was saved
     * must not cost the agent the rest. It is dropped at render instead.
     */
    public function test_a_stay_whose_check_in_has_passed_is_still_accepted(): void
    {
        $user = $this->hotelUser();

        $this->actingAs($user)
            ->postJson(route('hotels.recent'), ['recent' => $this->sampleRecent(-3)])
            ->assertNoContent();
    }
}
