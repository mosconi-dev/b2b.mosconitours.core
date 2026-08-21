<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contributions within a level became cumulative, so a level is no longer one row.
 *
 * An agency running a base percentage, a service fee and an international surcharge
 * contributes three rungs to one booking, and each is recorded separately — aggregating
 * them into a single row would save the total and lose the only thing this table exists
 * to answer: WHICH rule produced this price.
 *
 * The guard the old key provided is kept rather than dropped. `(booking_id, level)`
 * existed so an engine that somehow ran twice failed loudly instead of quietly doubling
 * the margin; `(booking_id, level, pricing_rule_id)` still refuses the same rule twice
 * on the same booking, which is exactly what a double run looks like.
 */
return new class extends Migration
{
    /**
     * The new key is added BEFORE the old one is dropped, and that order is required.
     * `booking_id` carries a foreign key, MySQL needs an index leading with it, and the
     * old unique was the only one — dropping first fails with "needed in a foreign key
     * constraint". The new key leads with `booking_id` too, so it takes over the job.
     */
    public function up(): void
    {
        Schema::table('booking_price_layers', function (Blueprint $table) {
            $table->unique(['booking_id', 'level', 'pricing_rule_id']);
        });

        Schema::table('booking_price_layers', function (Blueprint $table) {
            $table->dropUnique(['booking_id', 'level']);
        });
    }

    public function down(): void
    {
        Schema::table('booking_price_layers', function (Blueprint $table) {
            $table->unique(['booking_id', 'level']);
        });

        Schema::table('booking_price_layers', function (Blueprint $table) {
            $table->dropUnique(['booking_id', 'level', 'pricing_rule_id']);
        });
    }
};
