<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the reversal link added in 2026_08_10_000007.
 *
 * Entry-level reversal was a second correction mechanism on top of manual
 * adjustment, which already covers the same ground. A discrepancy spotted before
 * approval is rejected and reissued; one spotted afterwards is corrected with a
 * debit adjustment.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Order matters and differs by driver: MySQL refuses to drop the unique index
        // while the foreign key still leans on it, while SQLite rebuilds the table on
        // dropColumn and then trips over the orphaned index. Dropping the constraint,
        // then the index, then the column satisfies both.
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropForeign(['reversed_transaction_id']);
        });

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropUnique(['reversed_transaction_id']);
        });

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropColumn('reversed_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->foreignId('reversed_transaction_id')->nullable()->unique()->after('source_id')
                ->constrained('wallet_transactions')->nullOnDelete();
        });
    }
};
