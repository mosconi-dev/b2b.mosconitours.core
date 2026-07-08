<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $admin = Role::updateOrCreate(
            ['name' => 'admin'],
            ['label' => 'Administrator', 'description' => 'Full access to every module.', 'is_system' => true],
        );
        $admin->permissions()->sync(Permission::pluck('id'));

        $itp = Role::updateOrCreate(
            ['name' => 'itp'],
            ['label' => 'ITP', 'description' => 'Independent travel professional.', 'is_system' => true],
        );
        $itp->permissions()->sync($this->idsFor([
            'flight.view', 'flight.search', 'flight.book',
            'booking.view', 'booking.create',
            'apilog.view',
        ]));

        $resa = Role::updateOrCreate(
            ['name' => 'resa'],
            ['label' => 'Reservations', 'description' => 'Reservations desk.', 'is_system' => true],
        );
        $resa->permissions()->sync($this->idsFor([
            'flight.view', 'flight.search',
            'booking.view',
        ]));
    }

    /**
     * @param  array<int, string>  $names
     * @return Collection<int, int>
     */
    private function idsFor(array $names): Collection
    {
        return Permission::whereIn('name', $names)->pluck('id');
    }
}
