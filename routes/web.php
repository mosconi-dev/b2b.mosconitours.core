<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AgencyController;
use App\Http\Controllers\Admin\AgencyRoleController;
use App\Http\Controllers\Admin\AgencyUserController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\HotelCatalogueController;
use App\Http\Controllers\Admin\HotelSettingController;
use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\ApiLogController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\FlightController;
use App\Http\Controllers\HotelBookingController;
use App\Http\Controllers\HotelController;
use App\Http\Controllers\HotelSuggestController;
use App\Http\Controllers\LoadRequestController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\WalletAdjustmentController;
use App\Http\Controllers\WalletController;
use App\Models\WalletLoadRequest;
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
    // Steps 2–5 of the wizard, on the flights prefix for the same reason the hotel
    // wizard sits on /hotels: what is being booked belongs in the URL until there is
    // a booking. Only the record that comes out of it is product-agnostic, and that
    // stays at /bookings/{booking}.
    //
    // Completing a booking now issues the ticket, so the wizard itself requires the
    // ability to spend — better to refuse at the door than after ten minutes of
    // passenger entry.
    Route::get('/flights/book', [BookingController::class, 'create'])->name('flights.book')
        ->middleware(['can:booking.create', 'can:flight.issue']);
    Route::post('/flights/bookings', [BookingController::class, 'store'])->name('flights.bookings.store')
        ->middleware(['can:booking.create', 'can:flight.issue']);
    // The money step, and the only one. There is no hold: this queues Book → Ticket
    // as a single act, exactly as the system live today does it. Both abilities are
    // required because it always ends in a ticket.
    Route::post('/flights/bookings/{booking}/fulfil', [BookingController::class, 'fulfil'])
        ->name('flights.bookings.fulfil')->whereNumber('booking')
        ->middleware(['can:flight.book', 'can:flight.issue']);

    Route::get('/hotels', [HotelController::class, 'index'])->name('hotels')->middleware('can:hotel.view');
    // Registered before /hotels/{code} so the literal segment wins the match.
    Route::get('/hotels/suggest', HotelSuggestController::class)->name('hotels.suggest')
        ->middleware('can:hotel.search');
    Route::post('/hotels/search', [HotelController::class, 'search'])->name('hotels.search')
        ->middleware('can:hotel.search');
    // Shortcuts back into the search form, so gated on seeing the page rather than on
    // running a search — the same reading as flights.recent.
    Route::post('/hotels/recent', [HotelController::class, 'recent'])->name('hotels.recent')
        ->middleware('can:hotel.view');
    // Steps 3–5 of the wizard. Also before /hotels/{code}, which matches numbers only
    // but would still be the more confusing failure if it ever widened.
    Route::get('/hotels/book', [HotelBookingController::class, 'create'])->name('hotels.book')
        ->middleware('can:hotel.book');
    Route::post('/hotels/bookings', [HotelBookingController::class, 'store'])->name('hotels.bookings.store')
        ->middleware('can:hotel.book');
    // The money step. Separate from store(): creating the booking takes the agency's
    // money, this takes the room.
    Route::post('/hotels/bookings/{booking}/book', [HotelBookingController::class, 'book'])
        ->name('hotels.bookings.book')->whereNumber('booking')->middleware('can:hotel.book');
    Route::get('/hotels/bookings/{booking}/voucher', [HotelBookingController::class, 'voucher'])
        ->name('hotels.bookings.voucher')->whereNumber('booking')->middleware('can:booking.view');
    // A read, but a POST: it writes down what TBO answers, and a link a browser can
    // prefetch is not the right shape for something that corrects a booking's status.
    Route::post('/hotels/bookings/{booking}/refresh', [HotelBookingController::class, 'refresh'])
        ->name('hotels.bookings.refresh')->whereNumber('booking')->middleware('can:hotel.view');
    // Its own right, not part of hotel.book: this moves money back out of a confirmed
    // booking, and the charge for doing it is rarely nothing.
    Route::post('/hotels/bookings/{booking}/cancel', [HotelBookingController::class, 'cancel'])
        ->name('hotels.bookings.cancel')->whereNumber('booking')->middleware('can:hotel.cancel');
    // Step 2 on its own page, the way a fare gets one on the flight side.
    Route::get('/hotels/{code}/rooms', [HotelController::class, 'rooms'])->name('hotels.rooms')
        ->whereNumber('code')->middleware('can:hotel.search');
    Route::get('/hotels/{code}', [HotelController::class, 'show'])->name('hotels.show')
        ->whereNumber('code')->middleware('can:hotel.view');

    // What a booking is once it exists, whatever product made it: flights and hotels
    // both end up here. The steps that create one are product-specific and live on
    // /flights and /hotels respectively.
    Route::prefix('bookings')->name('bookings.')->group(function () {
        Route::get('/', [BookingController::class, 'index'])->name('index')->middleware('can:booking.view');
        Route::get('/{booking}', [BookingController::class, 'show'])->name('show')->whereNumber('booking')->middleware('can:booking.view');
        // The printable document. Read-only and offline — it renders from what we
        // already stored, so a passenger at a check-in desk never depends on TBO.
        Route::get('/{booking}/eticket', [BookingController::class, 'eticket'])->name('eticket')
            ->whereNumber('booking')->middleware('can:booking.view');
        // Where the booking page follows a queued Book/Ticket to its ending.
        Route::get('/{booking}/status', [BookingController::class, 'status'])->name('status')
            ->whereNumber('booking')->middleware('can:booking.view');
    });
    /*
    | Wallet — the agency e-wallet and its load-request cycle. Every step is
    | permission-gated; the policy adds agency scope, the pending check and
    | four-eyes on approval.
    */
    Route::prefix('wallet')->name('wallet.')->group(function () {
        Route::get('/', [WalletController::class, 'index'])->name('index')->middleware('can:wallet.view');

        // Manual correction. Posts a new entry rather than editing history;
        // authorization is the wallet.adjust permission via policy.
        Route::post('/{wallet}/adjustments', [WalletAdjustmentController::class, 'store'])->name('adjust')
            ->whereNumber('wallet');

        Route::prefix('requests')->name('requests.')->group(function () {
            Route::get('/', [LoadRequestController::class, 'index'])->name('index')->middleware('can:wallet.load.view');
            Route::get('/create', [LoadRequestController::class, 'create'])->name('create')
                ->middleware('can:create,'.WalletLoadRequest::class);
            Route::post('/', [LoadRequestController::class, 'store'])->name('store');
            // approve and reject are two outcomes of one act, so they share `review`.
            Route::patch('/{loadRequest}/approve', [LoadRequestController::class, 'approve'])->name('approve');
            Route::patch('/{loadRequest}/reject', [LoadRequestController::class, 'reject'])->name('reject');
            Route::patch('/{loadRequest}/cancel', [LoadRequestController::class, 'cancel'])->name('cancel')
                ->middleware('can:cancel,loadRequest');
        });
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

    Route::prefix('agencies')->name('agencies.')->group(function () {
        Route::get('/', [AgencyController::class, 'index'])->name('index')->middleware('can:agency.view');
        Route::get('/create', [AgencyController::class, 'create'])->name('create')->middleware('can:agency.create');
        Route::post('/', [AgencyController::class, 'store'])->name('store');
        // Registered after /create so the literal segment wins the match.
        Route::get('/{agency}', [AgencyController::class, 'show'])->name('show')
            ->whereNumber('agency')->middleware('can:view,agency');
        // Provisioning from inside an agency — the URL stays under /admin/agencies.
        // Guarded twice: the ability itself, and the right to touch this agency.
        Route::get('/{agency}/users/create', [AgencyUserController::class, 'create'])->name('users.create')
            ->middleware(['can:user.create', 'can:view,agency']);
        Route::post('/{agency}/users', [AgencyUserController::class, 'store'])->name('users.store');
        Route::get('/{agency}/roles/create', [AgencyRoleController::class, 'create'])->name('roles.create')
            ->middleware(['can:role.create', 'can:view,agency']);
        Route::post('/{agency}/roles', [AgencyRoleController::class, 'store'])->name('roles.store');

        Route::get('/{agency}/edit', [AgencyController::class, 'edit'])->name('edit')->middleware('can:update,agency');
        Route::put('/{agency}', [AgencyController::class, 'update'])->name('update');
        Route::patch('/{agency}/toggle-active', [AgencyController::class, 'toggleActive'])->name('toggle-active')->middleware('can:toggleActive,agency');
        Route::delete('/{agency}', [AgencyController::class, 'destroy'])->name('destroy')->middleware('can:delete,agency');
    });

    Route::prefix('users')->name('users.')->group(function () {
        Route::get('/', [UserController::class, 'index'])->name('index')->middleware('can:user.view');
        Route::get('/create', [UserController::class, 'create'])->name('create')->middleware('can:user.create');
        Route::post('/', [UserController::class, 'store'])->name('store');
        Route::get('/{user}/edit', [UserController::class, 'edit'])->name('edit')->middleware('can:update,user');
        Route::get('/{user}/logs', [UserController::class, 'logs'])->name('logs')->middleware(['can:apilog.view', 'can:view,user']);
        Route::put('/{user}', [UserController::class, 'update'])->name('update');
        Route::patch('/{user}/toggle-active', [UserController::class, 'toggleActive'])->name('toggle-active')->middleware('can:toggleActive,user');
        Route::put('/{user}/password', [UserController::class, 'resetPassword'])->name('password');
        Route::delete('/{user}', [UserController::class, 'destroy'])->name('destroy')->middleware('can:delete,user');
    });

    Route::prefix('roles')->name('roles.')->group(function () {
        Route::get('/', [RoleController::class, 'index'])->name('index')->middleware('can:role.view');
        Route::post('/', [RoleController::class, 'store'])->name('store');
        Route::get('/{role}/edit', [RoleController::class, 'edit'])->name('edit')->middleware('can:update,role');
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

    /*
    | The hotel catalogue. TBO's Search takes hotel codes and nothing else, so this
    | is not a cache — it is curated inventory someone has to keep current.
    */
    Route::prefix('hotel-catalogue')->name('hotel-catalogue.')->group(function () {
        Route::get('/', [HotelCatalogueController::class, 'index'])->name('index')
            ->middleware('can:supplier.tbohotel.view');
        Route::patch('/cities/{city}', [HotelCatalogueController::class, 'toggleCity'])->name('cities.toggle')
            ->whereNumber('city')->middleware('can:supplier.tbohotel.sync');
        // Many at once, or every city a filter matches. One country is 194 rows at 25
        // to a page, and carrying a dozen destinations one click at a time is how a
        // catalogue stays at two cities.
        Route::post('/cities/carry', [HotelCatalogueController::class, 'carryCities'])->name('cities.carry')
            ->middleware('can:supplier.tbohotel.sync');
        Route::post('/sync', [HotelCatalogueController::class, 'sync'])->name('sync')
            ->middleware('can:supplier.tbohotel.sync');
    });

    /*
    | TBO Hotel's own settings. Separate from admin/settings, which is TBO Air's and
    | is mostly token management — hotels have no token to manage.
    */
    Route::prefix('tbo-hotel')->name('tbo-hotel.')->group(function () {
        Route::get('settings', [HotelSettingController::class, 'index'])->name('settings')
            ->middleware('can:supplier.tbohotel.view');
        Route::put('settings', [HotelSettingController::class, 'update'])->name('settings.update')
            ->middleware('can:supplier.tbohotel.manage');
        Route::post('cache/flush', [HotelSettingController::class, 'flushCache'])->name('cache.flush')
            ->middleware('can:supplier.tbohotel.manage');
    });

    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/', [SettingController::class, 'index'])->name('index')->middleware('can:setting.view');
        Route::put('/tbo', [SettingController::class, 'update'])->name('tbo.update')->middleware('can:supplier.tbo.manage');
        Route::put('/tbo/env/{env}', [SettingController::class, 'updateEnvironment'])->name('tbo.env')
            ->whereIn('env', ['test', 'live'])->middleware('can:supplier.tbo.manage');
        Route::post('/tbo/flush/{env}', [SettingController::class, 'flushToken'])->name('tbo.flush')
            ->whereIn('env', ['test', 'live'])->middleware('can:supplier.tbo.manage');
        // Our balance WITH TBO — a read, so view rather than manage.
        Route::post('/tbo/balance', [SettingController::class, 'refreshBalance'])->name('tbo.balance')
            ->middleware('can:supplier.tbo.view');
    });
});

require __DIR__.'/auth.php';
