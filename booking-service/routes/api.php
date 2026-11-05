<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\ShowInventoryController;

Route::post(
    '/shows/{showId}/inventory',
    [ShowInventoryController::class, 'store']
);

Route::get(
    '/shows/{showId}/availability',
    [BookingController::class, 'availability']
);

Route::post(
    '/bookings/lock',
    [BookingController::class, 'lockSeats']
);

Route::get(
    '/bookings/{id}',
    [BookingController::class, 'show']
);

Route::post(
    '/bookings/{id}/release',
    [BookingController::class, 'release']
);