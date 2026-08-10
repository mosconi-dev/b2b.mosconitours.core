<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The one agency a user belongs to. NULL means platform staff (not bound
            // to any agency) — deliberately restrictOnDelete rather than nullOnDelete,
            // because nulling on delete would silently widen a member's scope.
            $table->foreignId('agency_id')->nullable()->after('email')
                ->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['agency_id']);
            $table->dropColumn('agency_id');
        });
    }
};
