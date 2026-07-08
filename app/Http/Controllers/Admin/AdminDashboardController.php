<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.dashboard', [
            'stats' => [
                ['label' => 'Total Users', 'value' => User::count(), 'sub' => User::where('is_active', true)->count().' active', 'tone' => 'text-emerald-600'],
                ['label' => 'Roles', 'value' => Role::count(), 'sub' => Role::where('is_system', true)->count().' built-in', 'tone' => 'text-gray-500'],
                ['label' => 'Permissions', 'value' => Permission::count(), 'sub' => 'across all modules', 'tone' => 'text-gray-500'],
                ['label' => 'Inactive Users', 'value' => User::where('is_active', false)->count(), 'sub' => 'deactivated', 'tone' => 'text-amber-600'],
            ],
        ]);
    }
}
