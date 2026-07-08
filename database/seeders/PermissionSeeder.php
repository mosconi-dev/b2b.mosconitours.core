<?php

namespace Database\Seeders;

use App\Services\Rbac\PermissionRegistry;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistry::class)->sync();
    }
}
