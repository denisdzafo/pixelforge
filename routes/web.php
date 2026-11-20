<?php

use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('generate', [GenerationController::class, 'create'])->name('generations.create');
    Route::post('generations', [GenerationController::class, 'store'])
        ->middleware('throttle:generations')
        ->name('generations.store');
    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::post('generations/{generation}/retry', [GenerationController::class, 'retry'])
        ->middleware('throttle:generations')
        ->name('generations.retry');

    Route::get('credits', [CreditController::class, 'index'])->name('credits.index');
    Route::post('credits/purchase', [CreditController::class, 'purchase'])
        ->middleware('throttle:10,1')
        ->name('credits.purchase');

    Route::get('admin/report', ReportController::class)
        ->middleware('can:viewAdminReport')
        ->name('admin.report');
});

require __DIR__.'/settings.php';
