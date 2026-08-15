<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a rule exists, in the words of whoever added it.
 *
 * Rules are cumulative, so a strategy accumulates them — a base rate, a peak-season
 * surcharge, a service fee agreed with one supplier. What each one adds is obvious from
 * its figures; *why it was added* is not, and it is the first thing anyone asks six
 * months later when deciding whether it can go.
 *
 * Optional on purpose. Requiring it would get "test" typed into it.
 *
 * It is copied into the snapshot on every booking like the rest of the rule, so a price
 * can still explain its own reasoning after the rule itself has been deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_rules', function (Blueprint $table) {
            $table->string('description', 255)->nullable()->after('pricing_strategy_id');
        });
    }

    public function down(): void
    {
        Schema::table('pricing_rules', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
