<?php

use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\ShowInventoryController;
use App\Http\Middleware\AuthenticateUserToken;
use Illuminate\Support\Facades\Route;

Route::post(
    '/shows/{showId}/inventory',
    [ShowInventoryController::class, 'store']
);

Route::get(
    '/shows/{showId}/availability',
    [BookingController::class, 'availability']
);

Route::middleware(AuthenticateUserToken::class)->group(function () {
    Route::post(
        '/bookings/lock',
        [BookingController::class, 'lockSeats']
    );

    Route::get(
        '/bookings/{id}',
        [BookingController::class, 'show']
    );

    Route::get(
        '/bookings/{id}/ticket',
        [BookingController::class, 'ticket']
    );

    Route::post(
        '/bookings/{id}/release',
        [BookingController::class, 'release']
    );

    Route::post(
        '/bookings/{id}/cancel',
        [BookingController::class, 'cancel']
    );
});

Route::post(
    '/bookings/{id}/confirm-payment',
    [BookingController::class, 'confirmPayment']
);
