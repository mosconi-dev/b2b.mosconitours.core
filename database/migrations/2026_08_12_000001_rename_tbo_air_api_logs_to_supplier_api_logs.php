<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The API log was named for the only supplier that existed when it was written.
 *
 * Hotels are the second, and they must land in the same table: the log pages are
 * where a failing call is diagnosed and where evidence for TBO is gathered, and a
 * supplier that logs somewhere else is a supplier nobody can debug.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('tbo_air_api_logs', 'supplier_api_logs');

        Schema::table('supplier_api_logs', function (Blueprint $table) {
            // Every existing row is a flight call, so the default backfills them.
            $table->string('supplier', 16)->default('tboair')->index();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_api_logs', function (Blueprint $table) {
            $table->dropIndex(['supplier']);
            $table->dropColumn('supplier');
        });

        Schema::rename('supplier_api_logs', 'tbo_air_api_logs');
    }
};
