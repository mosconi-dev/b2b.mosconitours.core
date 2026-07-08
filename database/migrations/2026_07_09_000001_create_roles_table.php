<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();      // machine key: admin | itp | resa
            $table->string('label', 128);              // human display name
            $table->string('description', 255)->nullable();
            $table->boolean('is_system')->default(false)->index(); // protects built-in roles
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
