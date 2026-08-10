<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->string('contact_email')->nullable()->after('type');
            $table->string('contact_phone', 32)->nullable()->after('contact_email');
            $table->text('address')->nullable()->after('contact_phone');
            // Path on the `public` disk, e.g. agency-logos/ab12….webp — not a URL, so
            // the disk/domain can change without rewriting rows.
            $table->string('logo_path')->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn(['contact_email', 'contact_phone', 'address', 'logo_path']);
        });
    }
};
