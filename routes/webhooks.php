<?php

use App\Http\Controllers\PixelforgeWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)
    ->middleware('throttle:pixelforge-webhooks')
    ->name('webhooks.pixelforge');
