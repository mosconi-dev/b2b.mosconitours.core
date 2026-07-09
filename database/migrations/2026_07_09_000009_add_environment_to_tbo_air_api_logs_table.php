<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbo_air_api_logs', function (Blueprint $table) {
            $table->string('environment', 8)->nullable()->index()->after('type'); // test | live
        });
    }

    public function down(): void
    {
        Schema::table('tbo_air_api_logs', function (Blueprint $table) {
            $table->dropColumn('environment');
        });
    }
};
