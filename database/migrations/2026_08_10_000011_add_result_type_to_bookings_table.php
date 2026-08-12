<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TBO's `ResultRecommendationType` from the search response, which Book wants back
     * as `ResultType`.
     *
     * The second field in this class after `seats_available`: present on the search
     * response, absent from FareQuote, required by Book. Our logs show it as both 0
     * and 1, so it cannot be defaulted — it has to be carried from the search that
     * produced the fare.
     *
     * Nullable: bookings made before this have none, and a search that omits the field
     * should record its absence rather than invent a value.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedTinyInteger('result_type')->nullable()->after('seats_available');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('result_type');
        });
    }
};
