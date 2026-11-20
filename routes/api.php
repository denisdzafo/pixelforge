<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ItemController;

// No access token yet: limited per IP. refresh reads the httpOnly refresh token cookie.
Route::group([
    'prefix' => 'auth',
    'middleware' => 'throttle:auth',
], function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('refresh', [AuthController::class, 'refresh']);
});

Route::group([
    'prefix' => 'auth',
    'middleware' => ['jwtAuth', 'throttle:api'],
], function () {
    Route::get('me', [AuthController::class, 'me']);
    Route::post('logout', [AuthController::class, 'logout']);
});

Route::group([
    'prefix' => 'items',
    'middleware' => ['jwtAuth', 'throttle:api'],
], function () {
    Route::get('list', [ItemController::class, 'getItems']);
    Route::get('get', [ItemController::class, 'getItem']);
    Route::post('create', [ItemController::class, 'createItem']);
    Route::post('update', [ItemController::class, 'updateItem']);
    Route::post('delete', [ItemController::class, 'deleteItem']);
});
