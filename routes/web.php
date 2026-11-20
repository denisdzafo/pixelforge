<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

// SPA deep links (/items, /login, …): a page refresh loads the current vue-router path from the server, so every other
// GET path returns the SPA shell. Registered last; api/, build/, storage/ and the health check keep their own routes
// (and real 404s)
Route::get('{path}', function () {
    return view('welcome');
})->where('path', '^(?!api/|api$|build/|storage/|up$).+');
