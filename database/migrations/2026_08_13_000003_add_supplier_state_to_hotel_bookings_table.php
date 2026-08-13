<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What TBO says about the booking, in TBO's own words.
 *
 * Kept beside our own status rather than folded into it. Ours is a small state machine
 * an agency can act on; theirs has six spellings of "being cancelled" and is the thing
 * support needs to see verbatim when the two disagree. Flattening one into the other
 * loses exactly the information a disagreement is made of.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotel_bookings', function (Blueprint $table) {
            $table->string('supplier_status', 64)->nullable()->after('invoice_number');

            // Per room, added by TBO on 24 Apr 2026. A multi-room booking can be
            // cancelled a room at a time, which our single status cannot express.
            $table->json('room_statuses')->nullable()->after('supplier_status');

            // When we last asked. Without it the page cannot tell "TBO says confirmed"
            // from "TBO said confirmed a fortnight ago".
            $table->timestamp('refreshed_at')->nullable()->after('room_statuses');
        });
    }

    public function down(): void
    {
        Schema::table('hotel_bookings', function (Blueprint $table) {
            $table->dropColumn(['supplier_status', 'room_statuses', 'refreshed_at']);
        });
    }
};
