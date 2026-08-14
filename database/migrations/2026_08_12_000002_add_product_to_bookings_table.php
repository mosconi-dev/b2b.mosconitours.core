<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Opens the booking spine to products other than flights.
 *
 * `bookings` already carries everything a booking of any kind needs — reference,
 * agency, environment, the guarded status, the money and its wallet linkage. What
 * it does not carry is any notion that a booking might not be a flight:
 * `result_index` is NOT NULL, so a hotel row cannot physically be written.
 *
 * The flight-shaped columns stay where they are. Moving them into a detail table of
 * their own is the tidier end state, but it is churn on the one path that has a
 * live PNR against it, and nothing here forecloses it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('product', 16)->default('flight')->index()->after('reference');
            $table->string('supplier', 16)->default('tboair')->after('product');
            // The one identifier an agent quotes back to the supplier: a PNR for a
            // flight, a ConfirmationNumber for a hotel. On the spine rather than in
            // each detail table so the bookings list can show and search it without
            // knowing what kind of booking it is looking at.
            $table->string('supplier_reference')->nullable()->index()->after('booking_id');
        });

        // A hotel has no ResultIndex. Nothing else about the column changes.
        Schema::table('bookings', function (Blueprint $table) {
            $table->text('result_index')->nullable()->change();
        });

        DB::statement('update bookings set supplier_reference = pnr where pnr is not null');
    }

    /**
     * `result_index` stays nullable on the way back down: restoring NOT NULL would
     * fail against any hotel row, and a rollback should not depend on the data.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['product']);
            $table->dropIndex(['supplier_reference']);
            $table->dropColumn(['product', 'supplier', 'supplier_reference']);
        });
    }
};
