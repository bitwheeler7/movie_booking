<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\MovieController;
use App\Http\Controllers\Api\CityController;
use App\Http\Controllers\Api\TheatreController;
use App\Http\Controllers\Api\ScreenController;
use App\Http\Controllers\Api\SeatController;
use App\Http\Controllers\Api\ShowController;

Route::apiResource('movies', MovieController::class);
Route::apiResource('cities', CityController::class);
Route::apiResource('theatres', TheatreController::class);
Route::apiResource('screens', ScreenController::class);
Route::apiResource('seats', SeatController::class);
Route::apiResource('shows', ShowController::class);