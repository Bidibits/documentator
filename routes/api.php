<?php

use App\Http\Controllers\Api\ArchiveController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\FileController;
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
    Route::get('documents', [DocumentController::class, 'index']);
    Route::get('documents/suggest-code', [DocumentController::class, 'suggestCode']);
    Route::post('documents', [DocumentController::class, 'store']);
    Route::get('documents/{document}', [DocumentController::class, 'show'])->whereNumber('document');
    Route::post('documents/{document}/versions', [DocumentController::class, 'storeVersion'])->whereNumber('document');

    Route::get('audits', [AuditController::class, 'index']);

    Route::get('archive', [ArchiveController::class, 'index']);
    Route::get('archive/rules', [ArchiveController::class, 'rules']);
    Route::put('archive/rules/{kind}', [ArchiveController::class, 'saveRule'])->whereIn('kind', ['idle', 'old']);
    Route::post('archive/run', [ArchiveController::class, 'run']);
    Route::post('versions/{version}/archive', [ArchiveController::class, 'archive'])->whereNumber('version');
    Route::post('versions/{version}/restore', [ArchiveController::class, 'restore'])->whereNumber('version');

    Route::get('files/{file}', [FileController::class, 'download'])->whereNumber('file');
});
