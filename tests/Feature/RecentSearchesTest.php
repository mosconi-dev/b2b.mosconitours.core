<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TboAir\RecentSearchStore;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class RecentSearchesTest extends TestCase
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
     * @return array<int, array<string, mixed>>
     */
    private function sampleRecent(): array
    {
        return [[
            'id' => 'round~economy~2~0~0~2026-07-25~Manila (MNL)>Cebu (CEB)@2026-07-23',
            'tripType' => 'round',
            'cabin' => 'economy',
            'pax' => ['adults' => 2, 'children' => 0, 'infants' => 0],
            'segments' => [['origin' => 'Manila (MNL)', 'dest' => 'Cebu (CEB)', 'departure' => '2026-07-23']],
            'returnDate' => '2026-07-25',
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

        $stored = app(RecentSearchStore::class)->get($user->id);

        $this->assertCount(1, $stored);
        $this->assertSame('Manila (MNL) → Cebu (CEB)', $stored[0]['routeText']);
    }

    public function test_cached_recent_searches_are_rendered_on_the_flights_page(): void
    {
        $user = $this->flightUser();
        app(RecentSearchStore::class)->put($user->id, $this->sampleRecent());

        $this->actingAs($user)
            ->get(route('flights'))
            ->assertOk()
            ->assertSee('Manila (MNL) → Cebu (CEB)');
    }

    public function test_recent_searches_are_scoped_per_user(): void
    {
        $owner = $this->flightUser();
        $other = $this->flightUser();
        app(RecentSearchStore::class)->put($owner->id, $this->sampleRecent());

        $this->assertCount(1, app(RecentSearchStore::class)->get($owner->id));
        $this->assertCount(0, app(RecentSearchStore::class)->get($other->id));
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
        app(RecentSearchStore::class)->put($user->id, $this->sampleRecent());

        $this->actingAs($user)
            ->postJson(route('flights.recent'), ['recent' => []])
            ->assertNoContent();

        $this->assertCount(0, app(RecentSearchStore::class)->get($user->id));
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
