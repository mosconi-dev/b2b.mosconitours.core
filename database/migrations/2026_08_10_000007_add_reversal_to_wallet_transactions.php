<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corrections are posted, never edited: reversing an entry writes a new opposing
 * entry that points back at the original. The ledger stays append-only and the
 * mistake stays visible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            // Unique: an entry can be reversed at most once. This is the database-level
            // backstop against a double claw-back if two people click Reverse together.
            $table->foreignId('reversed_transaction_id')->nullable()->unique()->after('source_id')
                ->constrained('wallet_transactions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropForeign(['reversed_transaction_id']);
            $table->dropColumn('reversed_transaction_id');
        });
    }
};
