<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agencies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();   // machine/human key: mnl-main | cebu-outlet
            $table->string('name', 128);
            $table->string('type', 16)->index();    // AgencyType: main_office | outlet | itp

            // Which office this agency reports to, for reporting and markups only.
            // It carries NO authorization meaning — permissions never inherit from a
            // parent; each agency is an independent scope. restrictOnDelete keeps a
            // hard delete from orphaning the tree.
            $table->foreignId('parent_id')->nullable()->constrained('agencies')->restrictOnDelete();

            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agencies');
    }
};
