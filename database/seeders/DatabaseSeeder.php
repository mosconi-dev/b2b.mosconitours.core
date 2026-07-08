<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
        ]);

        // Idempotent: only create the seed user if it doesn't exist yet (the
        // factory sets password/verified/is_active, bypassing mass-assignment).
        $user = User::where('email', 'test@example.com')->first()
            ?? User::factory()->create(['name' => 'Test User', 'email' => 'test@example.com']);

        $user->roles()->syncWithoutDetaching(
            Role::where('name', 'admin')->pluck('id')
        );
    }
}
