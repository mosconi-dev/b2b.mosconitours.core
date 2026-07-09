<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\ApiLogController;
use App\Http\Controllers\BookingController;
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
    Route::get('/flights', [FlightController::class, 'index'])->name('flights')->middleware('can:flight.view');
    Route::post('/flights/recent', [FlightController::class, 'recent'])->name('flights.recent')->middleware('can:flight.view');
    Route::post('/flights/search', [FlightController::class, 'search'])->name('flights.search')->middleware('can:flight.search');
    Route::post('/flights/fare-quote', [FlightController::class, 'fareQuote'])->name('flights.fare-quote')->middleware('can:flight.search');
    Route::post('/flights/fare-rule', [FlightController::class, 'fareRule'])->name('flights.fare-rule')->middleware('can:flight.search');
    Route::post('/flights/ssr', [FlightController::class, 'ssr'])->name('flights.ssr')->middleware('can:flight.search');

    Route::prefix('bookings')->name('bookings.')->group(function () {
        Route::get('/', [BookingController::class, 'index'])->name('index')->middleware('can:booking.view');
        Route::get('/create', [BookingController::class, 'create'])->name('create')->middleware('can:booking.create');
        Route::post('/', [BookingController::class, 'store'])->name('store')->middleware('can:booking.create');
        Route::get('/{booking}', [BookingController::class, 'show'])->name('show')->whereNumber('booking')->middleware('can:booking.view');
    });
    Route::get('/api-logs', [ApiLogController::class, 'index'])->name('api-logs')->middleware('can:apilog.view');
    Route::get('/api-logs/{apiLog}', [ApiLogController::class, 'show'])->name('api-logs.show')->whereNumber('apiLog')->middleware('can:apilog.view');
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
        Route::get('/{user}/logs', [UserController::class, 'logs'])->name('logs')->middleware('can:apilog.view');
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

    Route::prefix('permissions')->name('permissions.')->group(function () {
        Route::get('/', [PermissionController::class, 'index'])->name('index')->middleware('can:permission.view');
        Route::post('/sync', [PermissionController::class, 'sync'])->name('sync')->middleware('can:permission.sync');
    });

    Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index')->middleware('can:audit.view');

    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/', [SettingController::class, 'index'])->name('index')->middleware('can:setting.view');
        Route::put('/tbo', [SettingController::class, 'update'])->name('tbo.update')->middleware('can:supplier.tbo.manage');
        Route::put('/tbo/env/{env}', [SettingController::class, 'updateEnvironment'])->name('tbo.env')
            ->whereIn('env', ['test', 'live'])->middleware('can:supplier.tbo.manage');
        Route::post('/tbo/flush/{env}', [SettingController::class, 'flushToken'])->name('tbo.flush')
            ->whereIn('env', ['test', 'live'])->middleware('can:supplier.tbo.manage');
    });
});

require __DIR__.'/auth.php';
