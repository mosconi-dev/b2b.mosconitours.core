<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();        // our own human reference
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('environment', 8);                 // test|live — stamped once, immutable
            $table->string('status', 16)->index();            // BookingStatus
            $table->string('trace_id')->nullable();
            $table->text('result_index');                     // opaque TBO token — far exceeds 255 chars
            $table->boolean('is_lcc')->default(false);
            $table->string('pnr')->nullable();
            $table->string('booking_id')->nullable();         // TBO's booking id (set at Book/Ticket)
            $table->string('currency', 8)->default('PHP');
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->json('quote');                            // FareQuote snapshot (binding price at quote time)
            $table->json('pax');                              // passengers snapshot
            $table->json('contact')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
