<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somewhere for a rule to keep more than one number.
 *
 * `value` is a single decimal(12,4), which is every calculation type up to now: a
 * percentage, a flat amount, a per-unit fee. A tier table is three or more pairs of
 * them, and a price point is an ending rather than an amount at all.
 *
 * One JSON column, once, rather than a column per type — the alternative is a migration
 * on a table holding live pricing every time a calculation shape is added, which is the
 * thing CalculatorRegistry exists to avoid.
 *
 * It is deliberately NOT `matchers`. That column answers *does this rule apply*; this
 * one answers *what does it compute*. Fusing them would make matchesAttributes() walk
 * keys that were never matchers and quietly fail to match anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_rules', function (Blueprint $table) {
            // Nullable: every type that fits in `value` leaves it empty, which is all of
            // them but one today.
            $table->json('params')->nullable()->after('value');
        });
    }

    public function down(): void
    {
        Schema::table('pricing_rules', function (Blueprint $table) {
            $table->dropColumn('params');
        });
    }
};
