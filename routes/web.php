<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\ApiLogController;
use App\Http\Controllers\FlightController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/flights', [FlightController::class, 'index'])->name('flights');
    Route::post('/flights/search', [FlightController::class, 'search'])->name('flights.search');
    Route::get('/api-logs', [ApiLogController::class, 'index'])->name('api-logs');
    Route::get('/api-logs/{apiLog}', [ApiLogController::class, 'show'])->name('api-logs.show')->whereNumber('apiLog');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

/*
|--------------------------------------------------------------------------
| Administration
|--------------------------------------------------------------------------
| Every route is gated by a registry permission (can:<module>.<action>).
| Per-instance rules (self-action) are enforced by the UserPolicy via
| can:<ability>,<model> middleware.
*/
Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', AdminDashboardController::class)->name('dashboard')->middleware('can:admin.access');

    Route::prefix('users')->name('users.')->group(function () {
        Route::get('/', [UserController::class, 'index'])->name('index')->middleware('can:user.view');
        Route::get('/create', [UserController::class, 'create'])->name('create')->middleware('can:user.create');
        Route::post('/', [UserController::class, 'store'])->name('store');
        Route::get('/{user}/edit', [UserController::class, 'edit'])->name('edit')->middleware('can:user.update');
        Route::put('/{user}', [UserController::class, 'update'])->name('update');
        Route::patch('/{user}/toggle-active', [UserController::class, 'toggleActive'])->name('toggle-active')->middleware('can:toggleActive,user');
        Route::put('/{user}/password', [UserController::class, 'resetPassword'])->name('password');
        Route::delete('/{user}', [UserController::class, 'destroy'])->name('destroy')->middleware('can:delete,user');
    });

    Route::prefix('roles')->name('roles.')->group(function () {
        Route::get('/', [RoleController::class, 'index'])->name('index')->middleware('can:role.view');
        Route::post('/', [RoleController::class, 'store'])->name('store');
        Route::get('/{role}/edit', [RoleController::class, 'edit'])->name('edit')->middleware('can:role.update');
        Route::put('/{role}', [RoleController::class, 'update'])->name('update');
        Route::put('/{role}/permissions', [RoleController::class, 'syncPermissions'])->name('permissions');
        Route::post('/{role}/duplicate', [RoleController::class, 'duplicate'])->name('duplicate');
        Route::delete('/{role}', [RoleController::class, 'destroy'])->name('destroy')->middleware('can:delete,role');
    });
});

require __DIR__.'/auth.php';
