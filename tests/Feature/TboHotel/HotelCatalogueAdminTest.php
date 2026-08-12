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
