<?php

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

require __DIR__.'/auth.php';
