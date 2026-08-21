<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where markup is configured: one strategy per agency, many rules inside it.
 *
 * Pricing is CUMULATIVE across levels and FIRST-MATCH within one. The tables encode
 * both halves: `agency_id` is unique, so a level cannot have two strategies competing
 * to be "the live one", and `priority` orders the rules inside a strategy so exactly
 * one of them contributes.
 *
 * The Main Office is an ordinary agency row here, named by the
 * `pricing.main_office_agency_id` setting. That is why `agency_id` is NOT NULL: the
 * "NULL means platform default" pattern cannot be made unique in MySQL, which permits
 * many NULLs in a unique index, and it would leave the root strategy without a name,
 * a code or an audit scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_strategies', function (Blueprint $table) {
            $table->id();
            // One per agency. Seasonality lives on the rule, not on a second strategy —
            // two strategies on one agency asks "which is live?" on every quote.
            $table->foreignId('agency_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('pricing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pricing_strategy_id')->constrained()->cascadeOnDelete();

            // A validated string, not a DB enum: adding transfers or tours should be a
            // seeded row, not a migration on a table holding live pricing. '*' = any.
            $table->string('product', 16)->default('*');
            $table->string('supplier', 16)->nullable();      // null = any supplier
            $table->string('scope', 16)->default('any');     // domestic|international|any

            // Everything else a rule can narrow on. JSON because the matchable set
            // differs per product and grows with each one; affordable because the index
            // below narrows to a handful of rows before any of it is read.
            $table->json('matchers')->nullable();

            $table->string('calc_type', 32);
            $table->decimal('value', 12, 4);                 // four places: a percentage needs them

            // net = always the supplier price; running = the price as it stands after
            // the levels above. Required with no default — two 10% rules over 5,000
            // give 6,000 on net and 6,050 on running, and neither should be a guess.
            $table->string('basis', 16);
            $table->string('applies_to', 24)->default('total');

            $table->decimal('min_markup', 12, 2)->nullable();
            $table->decimal('max_markup', 12, 2)->nullable();
            $table->string('rounding', 8)->default('none');

            $table->unsignedSmallInteger('priority')->default(100);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->boolean('is_active')->default(true);

            // Bumped on every edit. A booking's price layer stores the version it used,
            // so a quote can tell whether the rule moved under it between search and book.
            $table->unsignedInteger('version')->default(1);

            $table->timestamps();

            // The matcher's access path: one strategy's active rules for one product,
            // in priority order.
            $table->index(['pricing_strategy_id', 'is_active', 'product', 'priority'], 'pricing_rules_match_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_rules');
        Schema::dropIfExists('pricing_strategies');
    }
};
