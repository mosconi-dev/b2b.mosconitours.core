<?php

namespace Tests\Unit;

use App\Enums\BookingStatus;
use PHPUnit\Framework\TestCase;

class BookingStatusTest extends TestCase
{
    public function test_legal_transitions_are_allowed(): void
    {
        $this->assertTrue(BookingStatus::Quoted->canTransitionTo(BookingStatus::Booked));
        $this->assertTrue(BookingStatus::Quoted->canTransitionTo(BookingStatus::Ticketed));
        $this->assertTrue(BookingStatus::Booked->canTransitionTo(BookingStatus::Ticketed));
        $this->assertTrue(BookingStatus::Ticketed->canTransitionTo(BookingStatus::Refunded));
    }

    public function test_illegal_transitions_are_refused(): void
    {
        $this->assertFalse(BookingStatus::Ticketed->canTransitionTo(BookingStatus::Quoted));
        $this->assertFalse(BookingStatus::Quoted->canTransitionTo(BookingStatus::Refunded));
        $this->assertFalse(BookingStatus::Cancelled->canTransitionTo(BookingStatus::Booked));
    }

    public function test_terminal_states(): void
    {
        $this->assertTrue(BookingStatus::Failed->isTerminal());
        $this->assertTrue(BookingStatus::Refunded->isTerminal());
        $this->assertFalse(BookingStatus::Quoted->isTerminal());
    }

    public function test_a_hotel_reaches_confirmed_without_passing_through_the_flight_states(): void
    {
        $this->assertTrue(BookingStatus::Quoted->canTransitionTo(BookingStatus::Confirmed));
        $this->assertTrue(BookingStatus::Processing->canTransitionTo(BookingStatus::Confirmed));
        $this->assertTrue(BookingStatus::Confirmed->canTransitionTo(BookingStatus::Cancelling));
        $this->assertTrue(BookingStatus::Cancelling->canTransitionTo(BookingStatus::Cancelled));
    }

    /**
     * The two branches share the enum, not the vocabulary. A hotel has nothing to
     * issue and a flight is never "confirmed", so crossing between them is a bug
     * the state machine should catch rather than a state anything can reach.
     */
    public function test_the_flight_and_hotel_branches_do_not_cross(): void
    {
        $this->assertFalse(BookingStatus::Booked->canTransitionTo(BookingStatus::Confirmed));
        $this->assertFalse(BookingStatus::Confirmed->canTransitionTo(BookingStatus::Ticketed));
        $this->assertFalse(BookingStatus::Confirmed->canTransitionTo(BookingStatus::Booked));
        $this->assertFalse(BookingStatus::Ticketed->canTransitionTo(BookingStatus::Cancelling));
    }

    /**
     * A cancellation TBO has not honoured yet is not a cancelled booking, and a
     * refusal must be able to put the room back rather than stranding it.
     */
    public function test_cancelling_is_in_flight_and_reversible(): void
    {
        $this->assertTrue(BookingStatus::Cancelling->isInFlight());
        $this->assertFalse(BookingStatus::Cancelling->isTerminal());
        $this->assertTrue(BookingStatus::Cancelling->canTransitionTo(BookingStatus::Confirmed));
    }

    public function test_confirmed_is_a_resting_state(): void
    {
        $this->assertFalse(BookingStatus::Confirmed->isInFlight());
        $this->assertFalse(BookingStatus::Confirmed->isTerminal());
    }
}
