<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The untransformed FareQuote response, kept verbatim alongside the `quote`
     * snapshot.
     *
     * `quote` is a UI-shaped transform (ItineraryMapper legs, a four-key price, a
     * four-key fare breakdown) and is deliberately lossy. TBO's Book method echoes
     * the whole priced itinerary back — NoOfSeatAvailable, OperatingCarrier,
     * ETicketEligible, FlightStatus, BookingClass, FareBasisCode,
     * ValidatingAirlineCode, LastTicketDate — with fares "sent exactly as received
     * in the fare quote, without modifications". None of those survive the
     * transform, so the raw response is stored too rather than guessing now which
     * fields Phase 4 will need.
     *
     * Nullable: bookings quoted before this migration have no raw response, and
     * backfilling one would mean re-pricing a fare that has almost certainly moved.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->json('quote_raw')->nullable()->after('quote');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('quote_raw');
        });
    }
};
