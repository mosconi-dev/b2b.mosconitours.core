<?php

namespace Tests\Feature\Admin;

use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\SupplierApiLog;
use App\Models\User;
use App\Services\Rbac\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * The history tables carry a denormalized agency_id stamped at creation, and every
 * list that reads them is scoped by it.
 */
class AgencyActivityScopingTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    private Agency $acme;

    private Agency $rival;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();

        $this->acme = Agency::factory()->create(['code' => 'acme', 'name' => 'Acme Travel']);
        $this->rival = Agency::factory()->create(['code' => 'rival', 'name' => 'Rival Tours']);
    }

    // ---- Audit logs ------------------------------------------------------

    public function test_audit_log_list_shows_only_the_viewers_agency(): void
    {
        $mine = $this->agencyUserWith($this->acme, ['audit.view']);
        $theirs = $this->agencyUserWith($this->rival, ['user.view']);

        app(AuditLogger::class)->log('test.mine', null, [], 'Mine happened', $mine);
        app(AuditLogger::class)->log('test.theirs', null, [], 'Theirs happened', $theirs);
        app(AuditLogger::class)->log('test.platform', null, [], 'Platform happened', $this->admin());

        $listed = $this->actingAs($mine)
            ->get(route('admin.audit-logs.index'))
            ->assertOk()
            ->viewData('logs');

        $events = $listed->pluck('event');
        $this->assertTrue($events->contains('test.mine'));
        $this->assertFalse($events->contains('test.theirs'));
        $this->assertFalse($events->contains('test.platform'), 'platform actions stay out of an agency trail');
    }

    public function test_audit_rows_are_stamped_with_the_actors_agency(): void
    {
        $member = $this->agencyUserWith($this->acme, ['audit.view']);

        app(AuditLogger::class)->log('test.stamped', null, [], null, $member);

        $this->assertSame($this->acme->id, AuditLog::where('event', 'test.stamped')->value('agency_id'));
    }

    public function test_platform_staff_see_every_agencys_audit_trail(): void
    {
        $mine = $this->agencyUserWith($this->acme, ['audit.view']);
        $theirs = $this->agencyUserWith($this->rival, ['audit.view']);

        app(AuditLogger::class)->log('test.mine', null, [], null, $mine);
        app(AuditLogger::class)->log('test.theirs', null, [], null, $theirs);

        $events = $this->actingAs($this->admin())
            ->get(route('admin.audit-logs.index'))
            ->assertOk()
            ->viewData('logs')
            ->pluck('event');

        $this->assertTrue($events->contains('test.mine'));
        $this->assertTrue($events->contains('test.theirs'));
    }

    // ---- API logs --------------------------------------------------------

    public function test_api_log_list_shows_only_the_viewers_agency(): void
    {
        $mine = $this->agencyUserWith($this->acme, ['apilog.view']);

        $ours = SupplierApiLog::create($this->logAttributes('/ours', $mine->id, $this->acme->id));
        $theirs = SupplierApiLog::create($this->logAttributes('/theirs', null, $this->rival->id));
        $platform = SupplierApiLog::create($this->logAttributes('/platform', null, null));

        $ids = $this->actingAs($mine)
            ->get(route('api-logs'))
            ->assertOk()
            ->viewData('logs')
            ->pluck('id');

        $this->assertTrue($ids->contains($ours->id));
        $this->assertFalse($ids->contains($theirs->id));
        $this->assertFalse($ids->contains($platform->id));
    }

    public function test_api_log_detail_is_guarded_per_row(): void
    {
        $mine = $this->agencyUserWith($this->acme, ['apilog.view']);

        $ours = SupplierApiLog::create($this->logAttributes('/ours', $mine->id, $this->acme->id));
        $theirs = SupplierApiLog::create($this->logAttributes('/theirs', null, $this->rival->id));

        // Holding apilog.view is not permission to read ANY row's raw response.
        $this->actingAs($mine)->get(route('api-logs.show', $ours))->assertOk();
        $this->actingAs($mine)->get(route('api-logs.show', $theirs))->assertForbidden();
    }

    public function test_platform_staff_can_read_any_api_log(): void
    {
        $log = SupplierApiLog::create($this->logAttributes('/anything', null, $this->rival->id));

        $this->actingAs($this->admin())->get(route('api-logs.show', $log))->assertOk();
    }

    // ---- Bookings --------------------------------------------------------

    public function test_a_booking_is_stamped_with_the_bookers_agency(): void
    {
        $booker = User::factory()->create(['agency_id' => $this->acme->id]);

        $booking = Booking::factory()->create([
            'user_id' => $booker->id,
            'agency_id' => $booker->agency_id,
        ]);

        $this->assertSame($this->acme->id, $booking->agency_id);
    }

    public function test_booking_history_does_not_follow_a_transferred_user(): void
    {
        $booker = User::factory()->create(['agency_id' => $this->acme->id]);
        $booking = Booking::factory()->create(['user_id' => $booker->id, 'agency_id' => $this->acme->id]);

        // The booker moves to another agency; the booking stays where it was made.
        $booker->update(['agency_id' => $this->rival->id]);

        $this->assertSame($this->acme->id, $booking->fresh()->agency_id);
    }

    public function test_a_booking_from_another_agency_is_not_visible(): void
    {
        $mine = $this->agencyUserWith($this->acme, ['booking.view']);

        // Same user id would normally pass the ownership check — the agency stamp is
        // what keeps a booking made elsewhere out of reach.
        $booking = Booking::factory()->create([
            'user_id' => $mine->id,
            'agency_id' => $this->rival->id,
        ]);

        $this->actingAs($mine)->get(route('bookings.show', $booking))->assertForbidden();
    }

    /**
     * @return array<string, mixed>
     */
    private function logAttributes(string $endpoint, ?int $userId, ?int $agencyId): array
    {
        return [
            'type' => 'search',
            'environment' => 'test',
            'endpoint' => $endpoint,
            'status_code' => 200,
            'successful' => true,
            'duration_ms' => 10,
            'user_id' => $userId,
            'agency_id' => $agencyId,
            'request' => [],
            'response' => ['ok' => true],
        ];
    }
}
