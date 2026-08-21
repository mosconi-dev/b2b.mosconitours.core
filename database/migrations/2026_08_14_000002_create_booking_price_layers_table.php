<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a booking explains its own price, one row per pricing level.
 *
 * Not a JSON blob on the booking. The blob answers forensics and nothing else, and the
 * report every level actually wants — "how much margin did Agency X earn last month?"
 * — is a SUM over this table. It is simultaneously the audit trail and the margin
 * ledger.
 *
 * `rule_snapshot` is a COPY of the rule, not a join to it. Rules are editable data; a
 * booking must be able to explain itself after its rule has been changed twice or
 * deleted. Same reasoning as `hotel_bookings` copying the property rather than joining
 * the catalogue.
 *
 * Append-only, like `wallet_transactions`: no `updated_at`, nothing ever edited. A
 * repriced booking writes new layers, it does not rewrite old ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_price_layers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();

            // 0 = Main Office, 1 = Agency. An int rather than a two-value enum because
            // the rungs need ordering regardless, and the engine loops the chain
            // without caring how long it is.
            $table->unsignedTinyInteger('level');

            // Whose margin this is — the column the margin report groups by.
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();

            // Convenience pointers. The snapshot below is the record; these go null
            // when a rule is deleted and the booking still reads correctly.
            $table->foreignId('pricing_strategy_id')->nullable();
            $table->foreignId('pricing_rule_id')->nullable();
            $table->json('rule_snapshot');

            $table->decimal('basis_amount', 14, 2);
            $table->decimal('markup_amount', 14, 2);
            $table->decimal('running_total', 14, 2);

            $table->timestamp('created_at')->nullable();

            // One layer per level. If a bug ever ran the engine twice over one booking,
            // the second insert fails loudly instead of quietly doubling the margin.
            $table->unique(['booking_id', 'level']);
            $table->index(['agency_id', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_price_layers');
    }
};
