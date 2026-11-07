<?php

use App\Http\Controllers\Api\SessionController;
use Illuminate\Support\Facades\Route;

/*
| The API the app talks to. Every route is behind GuardApiRequest (X-Documentator header, same origin).
| Errors come back as {"error": "<code>"}.
*/

Route::get('session', [SessionController::class, 'show']);
Route::post('login', [SessionController::class, 'login']);
Route::post('logout', [SessionController::class, 'logout']);

// Everything that needs a signed-in person goes inside here.
Route::middleware('documentator.signed-in')->group(function () {
    //
});
