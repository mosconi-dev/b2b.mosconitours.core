<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the Book was actually put on the wire.
 *
 * Sending the booking the moment the wizard finishes means the booking sits in
 * `processing` from the instant it is created — which is right for the page, but leaves
 * `processing` meaning two different things: "queued, nothing sent" and "sent, answer
 * unknown". Only the second must never be sent again, and a status alone cannot tell
 * them apart.
 *
 * This is the fact that can. It is stamped in the same breath as the request, before
 * the answer is known, because the dangerous case is precisely the one where no answer
 * ever comes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotel_bookings', function (Blueprint $table) {
            $table->timestamp('book_sent_at')->nullable()->after('booking_code');
        });
    }

    public function down(): void
    {
        Schema::table('hotel_bookings', function (Blueprint $table) {
            $table->dropColumn('book_sent_at');
        });
    }
};
