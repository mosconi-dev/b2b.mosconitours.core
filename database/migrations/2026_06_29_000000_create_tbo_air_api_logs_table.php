<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbo_air_api_logs', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32)->index();          // authenticate | search
            $table->string('endpoint');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->boolean('successful')->default(false)->index();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('request');
            $table->json('response')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbo_air_api_logs');
    }
};
