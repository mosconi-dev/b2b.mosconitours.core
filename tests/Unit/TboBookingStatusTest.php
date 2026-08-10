<?php

namespace Tests\Unit;

use App\Enums\BookingStatus;
use App\Enums\TboBookingStatus;
use PHPUnit\Framework\TestCase;

class TboBookingStatusTest extends TestCase
{
    public function test_it_covers_every_documented_code(): void
    {
        $this->assertSame(
            [0, 1, 2, 3, 4, 5, 6, 7, 8, 9],
            array_column(TboBookingStatus::cases(), 'value'),
        );
    }

    public function test_it_reads_a_status_off_a_response(): void
    {
        $this->assertSame(TboBookingStatus::Successful, TboBookingStatus::tryFromResponse(1));
        $this->assertSame(TboBookingStatus::InProgress, TboBookingStatus::tryFromResponse('8'));
        $this->assertNull(TboBookingStatus::tryFromResponse(null));
        $this->assertNull(TboBookingStatus::tryFromResponse('nonsense'));
        $this->assertNull(TboBookingStatus::tryFromResponse(99));
    }

    /**
     * The whole reason this enum exists: these four are not failures, and treating
     * them as such risks abandoning a PNR TBO went on to confirm.
     */
    public function test_the_unresolved_codes_are_ambiguous(): void
    {
        foreach ([
            TboBookingStatus::NotSet,
            TboBookingStatus::NotConfirmed,
            TboBookingStatus::Pending,
            TboBookingStatus::InProgress,
        ] as $status) {
            $this->assertTrue($status->isAmbiguous(), $status->name.' must force a GetBookingDetails read');
            $this->assertNull($status->toBookingStatus(), $status->name.' must refuse to map');
            $this->assertFalse($status->isFailure(), $status->name.' is not a failure');
            $this->assertFalse($status->isSuccess(), $status->name.' is not a success');
        }
    }

    public function test_the_decided_codes_are_not_ambiguous(): void
    {
        foreach ([
            TboBookingStatus::Successful,
            TboBookingStatus::Failed,
            TboBookingStatus::OtherFare,
            TboBookingStatus::OtherClass,
            TboBookingStatus::BookedOther,
            TboBookingStatus::Cancelled,
        ] as $status) {
            $this->assertFalse($status->isAmbiguous(), $status->name);
            $this->assertNotNull($status->toBookingStatus(), $status->name.' should map');
        }
    }

    public function test_it_maps_the_decided_codes(): void
    {
        $this->assertSame(BookingStatus::Ticketed, TboBookingStatus::Successful->toBookingStatus());
        $this->assertSame(BookingStatus::Failed, TboBookingStatus::Failed->toBookingStatus());
        $this->assertSame(BookingStatus::Cancelled, TboBookingStatus::Cancelled->toBookingStatus());
        $this->assertSame(BookingStatus::Booked, TboBookingStatus::BookedOther->toBookingStatus());
    }

    /**
     * The seat was gone at the fare or class we asked for. TBO refused to sell what
     * we requested, so it is a failure — not something to retry blindly.
     */
    public function test_other_fare_and_class_are_failures(): void
    {
        $this->assertTrue(TboBookingStatus::OtherFare->isFailure());
        $this->assertTrue(TboBookingStatus::OtherClass->isFailure());
        $this->assertSame(BookingStatus::Failed, TboBookingStatus::OtherFare->toBookingStatus());
    }

    /**
     * BookedOther means something *was* booked, so it must never be treated as a
     * failure — there is a live PNR to reconcile against.
     */
    public function test_booked_other_is_not_a_failure(): void
    {
        $this->assertFalse(TboBookingStatus::BookedOther->isFailure());
        $this->assertSame(BookingStatus::Booked, TboBookingStatus::BookedOther->toBookingStatus());
    }

    public function test_every_mapped_status_is_a_legal_move_from_quoted(): void
    {
        foreach (TboBookingStatus::cases() as $status) {
            $mapped = $status->toBookingStatus();

            if ($mapped === null) {
                continue;
            }

            $this->assertTrue(
                BookingStatus::Quoted->canTransitionTo($mapped),
                "A quoted booking must be able to reach {$mapped->value} for TBO {$status->name}",
            );
        }
    }
}
