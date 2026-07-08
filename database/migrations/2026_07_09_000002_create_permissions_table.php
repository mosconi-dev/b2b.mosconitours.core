<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 128)->unique();  // the Gate ability, e.g. flight.search
            $table->string('module', 96)->index();  // grouping key, may contain dots: supplier.amadeus
            $table->string('action', 48);           // final segment, e.g. view | search | sync
            $table->string('label', 128)->nullable();
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
