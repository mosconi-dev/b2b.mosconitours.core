<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-segment seat availability, captured at search time.
     *
     * TBO returns `NoOfSeatAvailable` on every search segment and then **drops it from
     * the FareQuote response** — but Book wants it back on every segment. It is the
     * only Book-relevant field lost between the two calls, so `quote_raw` alone cannot
     * assemble a Book payload.
     *
     * Stored separately rather than merged into `quote_raw`, which is deliberately a
     * verbatim copy of what TBO sent and should stay that way. The Book payload
     * builder zips the two together: segments from `quote_raw`, seats from here, in
     * segment order.
     *
     * Nullable: bookings made before this, and any quote whose search data was lost,
     * have none.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->json('seats_available')->nullable()->after('quote_raw');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('seats_available');
        });
    }
};
