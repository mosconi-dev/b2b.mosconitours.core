<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stamp the owning agency onto the activity tables so their lists can be scoped.
 *
 * Denormalized rather than resolved through users.agency_id at read time: these are
 * historical records, and a user who transfers between agencies must not drag their
 * booking and log history into the new one.
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private array $tables = ['bookings', 'audit_logs', 'tbo_air_api_logs'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                // nullOnDelete (unlike users.agency_id, which restricts): losing the
                // attribution on a history row is preferable to blocking the delete,
                // and a NULL agency simply drops out of every agency member's scope.
                $blueprint->foreignId('agency_id')->nullable()->after('user_id')
                    ->constrained()->nullOnDelete();
            });
        }

        // Backfill from each row's actor. Correlated subquery: portable across MySQL
        // and SQLite, and a no-op on a fresh test database.
        foreach ($this->tables as $table) {
            DB::table($table)
                ->whereNull('agency_id')
                ->whereNotNull('user_id')
                ->update([
                    'agency_id' => DB::raw("(select agency_id from users where users.id = {$table}.user_id)"),
                ]);
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropForeign(['agency_id']);
                $blueprint->dropColumn('agency_id');
            });
        }
    }
};
