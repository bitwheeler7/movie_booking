<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PaymentController;

Route::prefix('payments')->group(function () {

    Route::post('/intent', [
        PaymentController::class,
        'createIntent',
    ]);

    Route::post('/webhook', [
        PaymentController::class,
        'webhook',
    ]);

    Route::post('/refund-webhook', [
        PaymentController::class,
        'refundWebhook',
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