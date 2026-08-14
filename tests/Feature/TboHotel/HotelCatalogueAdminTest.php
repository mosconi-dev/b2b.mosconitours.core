<?php

namespace Tests\Feature\TboHotel;

use App\Jobs\SyncHotelCatalogue;
use App\Models\Hotel;
use App\Models\HotelCity;
use App\Models\HotelCountry;
use App\Models\HotelSyncRun;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class HotelCatalogueAdminTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    private int $made = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);

        HotelCountry::create(['source' => 'tbo', 'code' => 'PH', 'name' => 'Philippines']);
        HotelCity::create(['source' => 'tbo', 'code' => '127116', 'country_code' => 'PH', 'name' => 'Manila', 'is_enabled' => true, 'hotels_count' => 2701]);
        HotelCity::create(['source' => 'tbo', 'code' => '100834', 'country_code' => 'PH', 'name' => 'Alcoy']);
    }

    private function manager(): User
    {
        return $this->userWith(['supplier.tbohotel.view', 'supplier.tbohotel.sync']);
    }

    public function test_the_page_lists_cities_and_totals(): void
    {
        $this->actingAs($this->manager())->get('/admin/hotel-catalogue')
            ->assertOk()
            ->assertSee('Hotel Catalogue')
            ->assertSee('Manila')
            ->assertSee('Alcoy')
            ->assertSee('2,701');
    }

    public function test_viewing_requires_the_permission(): void
    {
        $this->actingAs($this->userWith(['flight.view']))
            ->get('/admin/hotel-catalogue')
            ->assertForbidden();
    }

    public function test_a_viewer_cannot_change_what_we_carry(): void
    {
        $city = HotelCity::where('code', '100834')->sole();

        $this->actingAs($this->userWith(['supplier.tbohotel.view']))
            ->patch("/admin/hotel-catalogue/cities/{$city->id}")
            ->assertForbidden();

        $this->assertFalse($city->fresh()->is_enabled);
    }

    public function test_toggling_a_city_is_recorded(): void
    {
        $city = HotelCity::where('code', '100834')->sole();

        $this->actingAs($this->manager())
            ->patch("/admin/hotel-catalogue/cities/{$city->id}")
            ->assertRedirect();

        $this->assertTrue($city->fresh()->is_enabled);
        $this->assertDatabaseHas('audit_logs', ['event' => 'tbohotel.city_enabled']);
    }

    // ------------------------------------------------------- carrying in bulk ----
    //
    // One country is 194 rows at 25 to a page. Carrying a dozen destinations one click
    // at a time is how a catalogue stays at two cities while everyone agrees it should
    // not, so the page can act on a selection or on a whole filter.

    /**
     * @param  array<int, string>  $names
     */
    private function cities(array $names): array
    {
        $ids = [];

        foreach ($names as $name) {
            $ids[] = HotelCity::create([
                // Counted across calls, not per call: a test that makes two batches
                // would otherwise collide on the unique (source, code).
                'source' => 'tbo', 'code' => (string) (900000 + $this->made++),
                'country_code' => 'PH', 'name' => $name,
            ])->id;
        }

        return $ids;
    }

    public function test_carrying_a_selection_of_cities(): void
    {
        $ids = $this->cities(['Boracay', 'Davao', 'Baguio']);

        $this->actingAs($this->manager())
            ->post('/admin/hotel-catalogue/cities/carry', ['carry' => 1, 'cities' => $ids])
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $m): bool => str_contains($m, '3 cities are now carried'));

        $this->assertSame(3, HotelCity::whereIn('id', $ids)->where('is_enabled', true)->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'tbohotel.cities_enabled']);
    }

    /**
     * The count reported back is the number of cities that changed, not the number
     * acted on — "12 cities are now carried" when eleven already were is a lie an
     * admin will act on.
     */
    public function test_cities_already_carried_are_not_counted_again(): void
    {
        $ids = $this->cities(['Boracay', 'Davao']);
        HotelCity::whereIn('id', $ids)->update(['is_enabled' => true]);
        $fresh = $this->cities(['Baguio']);

        $this->actingAs($this->manager())
            ->post('/admin/hotel-catalogue/cities/carry', ['carry' => 1, 'cities' => [...$ids, ...$fresh]])
            ->assertSessionHas('status', fn (string $m): bool => str_contains($m, '1 city is now carried'));
    }

    public function test_carrying_nothing_new_says_so_rather_than_claiming_a_change(): void
    {
        $ids = $this->cities(['Boracay']);
        HotelCity::whereIn('id', $ids)->update(['is_enabled' => true]);

        $this->actingAs($this->manager())
            ->post('/admin/hotel-catalogue/cities/carry', ['carry' => 1, 'cities' => $ids])
            ->assertSessionHas('status', 'Those cities were already carried.');
    }

    /**
     * "All matching" is resolved through the query the page was drawn from, so it can
     * never mean more than what the admin was looking at.
     */
    public function test_carrying_everything_a_filter_matches(): void
    {
        $this->cities(['Boracay', 'Davao']);
        HotelCountry::create(['source' => 'tbo', 'code' => 'TH', 'name' => 'Thailand']);
        $bangkok = HotelCity::create(['source' => 'tbo', 'code' => '800001', 'country_code' => 'TH', 'name' => 'Bangkok']);

        $this->actingAs($this->manager())
            ->post('/admin/hotel-catalogue/cities/carry', ['carry' => 1, 'all' => 1, 'country' => 'PH'])
            ->assertRedirect();

        // Every Philippine city, and nothing outside the filter.
        $this->assertSame(0, HotelCity::where('country_code', 'PH')->where('is_enabled', false)->count());
        $this->assertFalse($bangkok->fresh()->is_enabled);
    }

    public function test_a_search_filter_narrows_what_all_means(): void
    {
        $this->cities(['Boracay', 'Davao']);

        $this->actingAs($this->manager())
            ->post('/admin/hotel-catalogue/cities/carry', ['carry' => 1, 'all' => 1, 'q' => 'Bora'])
            ->assertRedirect();

        $this->assertTrue(HotelCity::where('name', 'Boracay')->value('is_enabled'));
        $this->assertFalse((bool) HotelCity::where('name', 'Davao')->value('is_enabled'));
    }

    /**
     * Stopping leaves the hotels in place, exactly as the single toggle does: they may
     * be referenced by a booking, and re-carrying should not mean re-downloading.
     */
    public function test_stopping_in_bulk_keeps_the_properties(): void
    {
        $manila = HotelCity::where('code', '127116')->sole();
        Hotel::create([
            'source' => 'tbo', 'code' => '1012705', 'city_code' => '127116',
            'country_code' => 'PH', 'name' => 'Jen s Comfy Home',
        ]);

        $this->actingAs($this->manager())
            ->post('/admin/hotel-catalogue/cities/carry', ['carry' => 0, 'cities' => [$manila->id]])
            ->assertSessionHas('status', fn (string $m): bool => str_contains($m, 'hotels have been kept'));

        $this->assertFalse($manila->fresh()->is_enabled);
        $this->assertSame(1, Hotel::where('city_code', '127116')->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'tbohotel.cities_disabled']);
    }

    public function test_carrying_in_bulk_needs_the_sync_permission(): void
    {
        $ids = $this->cities(['Boracay']);

        $this->actingAs($this->userWith(['supplier.tbohotel.view']))
            ->post('/admin/hotel-catalogue/cities/carry', ['carry' => 1, 'cities' => $ids])
            ->assertForbidden();

        $this->assertFalse((bool) HotelCity::find($ids[0])->is_enabled);
    }

    public function test_the_page_offers_the_bulk_controls_and_names_the_count(): void
    {
        $this->actingAs($this->manager())->get('/admin/hotel-catalogue?country=PH')
            ->assertOk()
            ->assertSee('Carry all 2 matching')
            ->assertSee('Carry selected');
    }

    /**
     * A sync is minutes of HTTP, so it must not be a request the browser waits on.
     */
    public function test_a_sync_is_queued_rather_than_run_inline(): void
    {
        Queue::fake();

        $this->actingAs($this->manager())
            ->post('/admin/hotel-catalogue/sync', ['scope' => 'hotels'])
            ->assertRedirect();

        Queue::assertPushed(SyncHotelCatalogue::class);
        $this->assertDatabaseHas('audit_logs', ['event' => 'tbohotel.catalogue_synced']);
    }

    public function test_syncing_hotels_with_nothing_carried_is_refused(): void
    {
        Queue::fake();
        HotelCity::query()->update(['is_enabled' => false]);

        $this->actingAs($this->manager())
            ->post('/admin/hotel-catalogue/sync', ['scope' => 'hotels'])
            ->assertRedirect()
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
    }

    public function test_an_unknown_scope_is_rejected(): void
    {
        Queue::fake();

        $this->actingAs($this->manager())
            ->post('/admin/hotel-catalogue/sync', ['scope' => 'everything'])
            ->assertSessionHasErrors('scope');

        Queue::assertNothingPushed();
    }

    /**
     * A run that skipped cities has to say which, on the page — that is the whole
     * difference from a sync that reports one string and loses the rest.
     */
    public function test_a_partial_run_names_what_it_skipped(): void
    {
        HotelSyncRun::create([
            'scope' => 'hotels',
            'target' => 'enabled',
            'status' => HotelSyncRun::COMPLETED,
            'processed' => 12,
            'failed' => 1,
            'failures' => [['target' => '114570', 'label' => 'Cebu City', 'reason' => 'QPS Exceeded']],
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        $this->actingAs($this->manager())->get('/admin/hotel-catalogue')
            ->assertOk()
            ->assertSee('1 skipped')
            ->assertSee('Cebu City')
            ->assertSee('QPS Exceeded');
    }

    public function test_suggest_offers_carried_cities_and_hotels(): void
    {
        Hotel::create([
            'source' => 'tbo', 'code' => '1012698', 'city_code' => '127116',
            'country_code' => 'PH', 'name' => 'Manila Bay Hotel', 'rating' => 4,
        ]);

        $this->actingAs($this->userWith(['hotel.search']))
            ->getJson('/hotels/suggest?q=Manila')
            ->assertOk()
            ->assertJsonPath('results.0.type', 'city')
            ->assertJsonPath('results.0.code', '127116')
            ->assertJsonPath('results.1.type', 'hotel')
            ->assertJsonPath('results.1.label', 'Manila Bay Hotel');
    }

    /**
     * Offering a city we hold no hotels for is offering a search that returns
     * nothing.
     */
    public function test_suggest_hides_cities_we_do_not_carry(): void
    {
        $this->actingAs($this->userWith(['hotel.search']))
            ->getJson('/hotels/suggest?q=Alcoy')
            ->assertOk()
            ->assertJsonCount(0, 'results');
    }

    /**
     * Carried and empty is a real state: carrying a city and pulling its properties are
     * two deliberate steps, and bulk carrying makes the gap between them wide. A city
     * offered in that gap answers "no availability", which an agent reads as no rooms
     * rather than as us never having looked.
     */
    public function test_suggest_hides_a_carried_city_whose_hotels_are_not_pulled_yet(): void
    {
        $city = HotelCity::where('code', '100834')->sole();
        $city->update(['is_enabled' => true, 'hotels_count' => 0]);

        $this->actingAs($this->userWith(['hotel.search']))
            ->getJson('/hotels/suggest?q=Alcoy')
            ->assertOk()
            ->assertJsonCount(0, 'results');

        $city->update(['hotels_count' => 12]);

        $this->actingAs($this->userWith(['hotel.search']))
            ->getJson('/hotels/suggest?q=Alcoy')
            ->assertOk()
            ->assertJsonPath('results.0.label', 'Alcoy');
    }

    public function test_suggest_ignores_a_term_too_short_to_mean_anything(): void
    {
        $this->actingAs($this->userWith(['hotel.search']))
            ->getJson('/hotels/suggest?q=M')
            ->assertOk()
            ->assertJsonCount(0, 'results');
    }

    public function test_suggest_requires_the_search_permission(): void
    {
        $this->actingAs($this->userWith(['hotel.view']))
            ->getJson('/hotels/suggest?q=Manila')
            ->assertForbidden();
    }
}
