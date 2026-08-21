<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Splits one money column into the three figures a marked-up booking actually has.
 *
 * `total_amount` is today both the supplier's rate and the amount the wallet is
 * debited, because those are the same number when there is no markup. They stop being
 * the same number the moment there is, and the difference is not cosmetic:
 * `TboHotelBookPayload` sends `total_amount` to TBO as `TotalFare`, which TBO compares
 * against its own figure and refuses on a mismatch. A sell price in that field breaks
 * every hotel booking.
 *
 *   net_amount    what the supplier charges us — what goes to TBO, what refunds
 *                 reconcile against
 *   cost_amount   net + the Main Office's markup — what the wallet is debited.
 *                 Defined as "the running total at the level above the booker", so it
 *                 keeps its meaning if another level is ever added
 *   markup_total  total − net, denormalised so the list and margin reports need no join
 *   total_amount  unchanged column, now explicitly the SELL price
 *
 * Every existing row backfills to net = cost = total and markup 0, which is exactly
 * what it already meant. No displayed price moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->decimal('net_amount', 12, 2)->default(0)->after('currency');
            $table->decimal('cost_amount', 12, 2)->default(0)->after('net_amount');
            $table->decimal('markup_total', 12, 2)->default(0)->after('total_amount');
        });

        DB::table('bookings')->update([
            'net_amount' => DB::raw('total_amount'),
            'cost_amount' => DB::raw('total_amount'),
            'markup_total' => 0,
        ]);
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['net_amount', 'cost_amount', 'markup_total']);
        });
    }
};
