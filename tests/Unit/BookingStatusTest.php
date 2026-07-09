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
}
