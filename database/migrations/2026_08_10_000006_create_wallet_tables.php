<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The agency e-wallet: one balance per agency, an append-only ledger behind it,
 * and the load-request workflow that credits it.
 *
 * The wallet belongs to the AGENCY, never to a user — every member draws on the
 * same balance, and a member leaving changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            // Unique: exactly one wallet per agency.
            $table->foreignId('agency_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('currency', 8)->default('PHP');
            $table->decimal('balance', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete(); // denormalized for scoping
            $table->string('direction', 8);                  // credit | debit
            $table->decimal('amount', 14, 2);
            $table->decimal('balance_after', 14, 2);         // the running balance, so the ledger reconciles alone
            $table->nullableMorphs('source');                // the load request, later a booking
            $table->string('description', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // who caused it
            $table->timestamp('created_at')->nullable()->index();  // append-only, no updated_at

            $table->index(['wallet_id', 'id']);
        });

        Schema::create('wallet_load_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();       // human handle, e.g. LR-A1B2C3D4
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 8)->default('PHP');
            $table->string('status', 16)->index();           // LoadRequestStatus
            $table->string('payment_reference')->nullable(); // the requester's bank/e-money reference
            $table->text('remarks')->nullable();

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_remarks')->nullable();

            // Set once, on approval. The unique index is the last line of defence
            // against a double credit if two approvals race past the status check.
            $table->foreignId('wallet_transaction_id')->nullable()->unique()
                ->constrained('wallet_transactions')->nullOnDelete();

            $table->timestamps();

            $table->index(['agency_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_load_requests');
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallets');
    }
};
