<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Search\FlightRecentSearches;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class FlightRecentSearchesTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function flightUser(): User
    {
        return $this->userWith(['flight.view', 'flight.search']);
    }

    /**
     * Dates are relative so the fixture cannot rot into the past and start tripping
     * the very filter these tests exist to check.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sampleRecent(int $departureDays = 30): array
    {
        $departure = Carbon::today()->addDays($departureDays)->toDateString();
        $return = Carbon::today()->addDays($departureDays + 2)->toDateString();

        return [[
            'id' => "round~economy~2~0~0~{$return}~Manila (MNL)>Cebu (CEB)@{$departure}",
            'tripType' => 'round',
            'cabin' => 'economy',
            'pax' => ['adults' => 2, 'children' => 0, 'infants' => 0],
            'segments' => [['origin' => 'Manila (MNL)', 'dest' => 'Cebu (CEB)', 'departure' => $departure]],
            'returnDate' => $return,
            'routeText' => 'Manila (MNL) → Cebu (CEB)',
            'dateText' => 'Jul 23 – Jul 25',
            'metaText' => '2 Pax · Economy',
        ]];
    }

    public function test_recent_searches_are_stored_in_the_per_user_cache(): void
    {
        $user = $this->flightUser();

        $this->actingAs($user)
            ->postJson(route('flights.recent'), ['recent' => $this->sampleRecent()])
            ->assertNoContent();

        $stored = app(FlightRecentSearches::class)->get($user->id);

        $this->assertCount(1, $stored);
        $this->assertSame('Manila (MNL) → Cebu (CEB)', $stored[0]['routeText']);
    }

    public function test_cached_recent_searches_are_rendered_on_the_flights_page(): void
    {
        $user = $this->flightUser();
        app(FlightRecentSearches::class)->put($user->id, $this->sampleRecent());

        $this->actingAs($user)
            ->get(route('flights'))
            ->assertOk()
            ->assertSee('Manila (MNL) → Cebu (CEB)');
    }

    /**
     * The bug this filter exists for: a departure that has passed cannot be searched
     * (`after_or_equal:today`), and the date picker will not render a date below its
     * minimum — so restoring one used to hand back a form with an empty departure
     * field and no explanation.
     */
    public function test_searches_whose_departure_has_passed_are_left_off_the_page(): void
    {
        $user = $this->flightUser();
        app(FlightRecentSearches::class)->put($user->id, array_merge(
            $this->sampleRecent(-3),
            $this->sampleRecent(30),
        ));

        $this->actingAs($user)
            ->get(route('flights'))
            ->assertOk()
            ->assertViewHas('recent', fn (array $recent): bool => count($recent) === 1
                && $recent[0]['segments'][0]['departure'] === Carbon::today()->addDays(30)->toDateString());

        // Filtered on the way out, not deleted: the cached list still holds both.
        $this->assertCount(2, app(FlightRecentSearches::class)->get($user->id));
    }

    /**
     * A multi-city trip is only bookable if all of it still lies ahead, so a stale
     * leg anywhere disqualifies the entry — not just the first one.
     */
    public function test_a_later_leg_in_the_past_also_disqualifies_the_search(): void
    {
        $user = $this->flightUser();
        $entry = $this->sampleRecent(5)[0];
        $entry['segments'][] = ['origin' => 'Cebu (CEB)', 'dest' => 'Manila (MNL)', 'departure' => Carbon::today()->subDay()->toDateString()];
        app(FlightRecentSearches::class)->put($user->id, [$entry]);

        $this->actingAs($user)
            ->get(route('flights'))
            ->assertOk()
            ->assertViewHas('recent', fn (array $recent): bool => $recent === []);
    }

    /**
     * A list is written as a whole, so one entry that aged out since it was saved
     * must not cost the agent the rest. It is dropped at render instead.
     */
    public function test_a_departure_in_the_past_is_still_accepted_on_write(): void
    {
        $user = $this->flightUser();

        $this->actingAs($user)
            ->postJson(route('flights.recent'), ['recent' => $this->sampleRecent(-3)])
            ->assertNoContent();

        $this->assertCount(1, app(FlightRecentSearches::class)->get($user->id));
    }

    public function test_recent_searches_are_scoped_per_user(): void
    {
        $owner = $this->flightUser();
        $other = $this->flightUser();
        app(FlightRecentSearches::class)->put($owner->id, $this->sampleRecent());

        $this->assertCount(1, app(FlightRecentSearches::class)->get($owner->id));
        $this->assertCount(0, app(FlightRecentSearches::class)->get($other->id));
    }

    public function test_storing_recent_searches_requires_flight_view_permission(): void
    {
        $user = $this->userWith([]); // no permissions

        $this->actingAs($user)
            ->postJson(route('flights.recent'), ['recent' => $this->sampleRecent()])
            ->assertForbidden();
    }

    public function test_an_empty_list_clears_the_cached_history(): void
    {
        $user = $this->flightUser();
        app(FlightRecentSearches::class)->put($user->id, $this->sampleRecent());

        $this->actingAs($user)
            ->postJson(route('flights.recent'), ['recent' => []])
            ->assertNoContent();

        $this->assertCount(0, app(FlightRecentSearches::class)->get($user->id));
    }

    public function test_the_list_is_bounded_to_six_entries(): void
    {
        $user = $this->flightUser();
        $entry = $this->sampleRecent()[0];
        $tooMany = [];
        for ($i = 0; $i < 7; $i++) {
            $tooMany[] = array_merge($entry, ['id' => "entry-$i"]);
        }

        $this->actingAs($user)
            ->postJson(route('flights.recent'), ['recent' => $tooMany])
            ->assertStatus(422)
            ->assertJsonValidationErrors('recent');
    }

    public function test_an_invalid_trip_type_is_rejected(): void
    {
        $user = $this->flightUser();
        $bad = $this->sampleRecent();
        $bad[0]['tripType'] = 'teleport';

        $this->actingAs($user)
            ->postJson(route('flights.recent'), ['recent' => $bad])
            ->assertStatus(422)
            ->assertJsonValidationErrors('recent.0.tripType');
    }
}
