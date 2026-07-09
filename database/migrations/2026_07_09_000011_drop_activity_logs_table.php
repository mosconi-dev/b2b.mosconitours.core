<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-user "Activity" feed now reads from audit_logs (create/update/delete
 * and auth events, keyed by actor), so the short-lived page-view activity_logs
 * table is no longer needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('activity_logs');
    }

    public function down(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 64)->index();
            $table->string('description')->nullable();
            $table->string('method', 8)->nullable();
            $table->string('route')->nullable();
            $table->string('url')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }
};
