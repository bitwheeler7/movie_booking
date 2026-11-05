<?php

use App\Http\Controllers\Api\PaymentController;
use App\Http\Middleware\AuthenticateUserToken;
use Illuminate\Support\Facades\Route;

Route::prefix('payments')->group(function () {

    Route::post('/webhook', [
        PaymentController::class,
        'webhook',
    ]);

    Route::post('/refund-webhook', [
        PaymentController::class,
        'refundWebhook',
    ]);

    Route::middleware(AuthenticateUserToken::class)->group(function () {
        Route::post('/intent', [
            PaymentController::class,
            'createIntent',
        ]);

        Route::get('/{id}', [
            PaymentController::class,
            'show',
        ]);

        Route::post('/{id}/refund', [
            PaymentController::class,
            'requestRefund',
        ]);
    });
});
