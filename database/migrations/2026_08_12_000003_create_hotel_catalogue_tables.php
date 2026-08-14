<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The local hotel catalogue.
 *
 * TBO's Search takes `HotelCodes` and nothing else — there is no "search this city"
 * call — so holding our own copy of the catalogue is a precondition for searching
 * at all, not a cache.
 *
 * Every table carries `source`, because a hotel is a hotel whether it came from TBO
 * or from a contracted-inventory agreement later. The unique key is (source, code):
 * two suppliers may legitimately use the same number for different properties.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_countries', function (Blueprint $table) {
            $table->id();
            $table->string('source', 16)->default('tbo');
            $table->string('code', 2);
            $table->string('name');
            $table->timestamps();

            $table->unique(['source', 'code']);
        });

        Schema::create('hotel_cities', function (Blueprint $table) {
            $table->id();
            $table->string('source', 16)->default('tbo');
            $table->string('code', 16);
            $table->string('country_code', 2)->index();
            $table->string('name')->index();      // autocomplete searches this
            // TBO sells more cities than we do. Hotels are only pulled for the ones
            // an admin turns on, so the catalogue stays the size of our business
            // rather than the size of TBO's.
            $table->boolean('is_enabled')->default(false)->index();
            $table->unsignedInteger('hotels_count')->default(0);
            $table->timestamp('hotels_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'code']);
        });

        Schema::create('hotels', function (Blueprint $table) {
            $table->id();
            $table->string('source', 16)->default('tbo');
            $table->string('code', 16);
            // The city we ASKED TBO for, which is not always the CityName it answers
            // with — Alcoy's hotels come back as "Cebu City". Search is driven off
            // this column, so it has to be the code that produced the hotel.
            $table->string('city_code', 16)->index();
            $table->string('country_code', 2)->index();
            $table->string('name')->index();
            $table->text('address')->nullable();
            $table->unsignedTinyInteger('rating')->nullable()->index();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // Everything below arrives only from HotelDetails, one batched call per
            // ~50 hotels, so it is filled by a second pass and may legitimately be
            // null on a freshly listed property.
            $table->longText('description')->nullable();
            $table->json('facilities')->nullable();
            $table->json('attractions')->nullable();
            $table->json('images')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('website')->nullable();
            $table->string('pin_code', 32)->nullable();
            $table->string('checkin_time', 16)->nullable();
            $table->string('checkout_time', 16)->nullable();
            $table->timestamp('detailed_at')->nullable();

            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'code']);
        });

        Schema::create('hotel_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 16);                    // countries|cities|hotels|details
            $table->string('target', 32)->nullable();       // the country or city it covered
            $table->string('status', 16)->index();          // running|completed|failed
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('failed')->default(0);
            // Which cities could not be fetched and why. The live system aborts the
            // whole run on the first failure and reports a single string; this is
            // what makes a re-run able to pick up where it stopped.
            $table->json('failures')->nullable();
            $table->text('message')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_sync_runs');
        Schema::dropIfExists('hotels');
        Schema::dropIfExists('hotel_cities');
        Schema::dropIfExists('hotel_countries');
    }
};
