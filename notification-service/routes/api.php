<?php

use App\Http\Controllers\Api\NotificationController;
use App\Http\Middleware\AuthenticateUserToken;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticateUserToken::class)->prefix('notifications')->group(function () {
    Route::get('/', [NotificationController::class, 'index']);
    Route::get('/unread', [NotificationController::class, 'unread']);
    Route::post('/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::get('/{id}/delivery-status', [NotificationController::class, 'deliveryStatus']);
    Route::post('/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::get('/{id}', [NotificationController::class, 'show']);
});
