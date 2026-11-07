<?php

use App\Http\Controllers\AppController;
use Illuminate\Support\Facades\Route;

Route::get('/', AppController::class);
Route::get('/index.html', AppController::class);
Route::get('/favicon.ico', fn () => response()->noContent());
