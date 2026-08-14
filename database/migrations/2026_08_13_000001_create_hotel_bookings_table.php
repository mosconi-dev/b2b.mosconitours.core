<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The hotel half of a booking.
 *
 * `bookings` carries what every product shares — reference, money, status, the wallet
 * charge. What only a hotel has lives here: the stay, the rate, and the two references
 * TBO hands back at different times.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained()->cascadeOnDelete();

            // The property, copied rather than joined: a booking must still render
            // years later if the catalogue row is re-synced, renamed or dropped.
            $table->string('hotel_code', 32)->index();
            $table->string('hotel_name');
            $table->string('city_code', 32)->nullable();
            $table->string('city')->nullable();
            $table->string('country_code', 2)->nullable();
            $table->string('address')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();

            // The stay.
            $table->date('check_in');
            $table->date('check_out');
            $table->unsignedSmallInteger('nights');
            $table->unsignedTinyInteger('rooms_count');
            $table->string('guest_nationality', 2);

            // The rate. `booking_code` is text, not string: the live codes run past
            // 100 characters and are segmented — "1012705!TB!1!TB!<uuid>!TB!N!TB!AFF!"
            // — and truncating one silently makes the booking unbookable.
            $table->text('booking_code');
            $table->string('meal_type', 64)->nullable();
            $table->boolean('is_refundable')->default(false);
            $table->boolean('with_transfers')->default(false);

            // PreBook's terms, which §18 makes final for the itinerary. Stored so a
            // refund can be computed, and a voucher printed, without asking TBO again.
            $table->json('room_names')->nullable();
            $table->json('cancel_policies')->nullable();
            $table->json('supplements')->nullable();
            $table->json('rate_conditions')->nullable();
            $table->json('amenities')->nullable();

            // What TBO gives back, in the order it gives it. ConfirmationNumber arrives
            // with the Book response; the hotel's own reference arrives later and is
            // polled for (§10.1), which is what the attempt counters are for.
            $table->string('confirmation_number', 64)->nullable()->index();
            $table->string('hotel_confirmation_number', 64)->nullable();
            $table->string('invoice_number', 64)->nullable();
            $table->unsignedSmallInteger('hcn_attempts')->default(0);
            $table->timestamp('hcn_next_attempt_at')->nullable()->index();

            // Cancellation. The charge is ours to compute from the stored policy —
            // TBO's Cancel response does not state it.
            $table->decimal('cancellation_charge', 12, 2)->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_bookings');
    }
};
