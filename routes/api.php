<?php

use App\Http\Controllers\Api\ArchiveController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Api\PasswordController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SessionController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
| The API the app talks to. Every route is behind GuardApiRequest (X-Documentator header, same origin).
| Errors come back as {"error": "<code>"}.
|
| What a route needs is named in documentator.can:<permission>; a role is a bundle of permissions
| (config/documentator.php, App\Auth\Access).
*/

Route::get('session', [SessionController::class, 'show']);
Route::post('login', [SessionController::class, 'login']);
Route::post('logout', [SessionController::class, 'logout']);

// Everything that needs a signed-in person goes inside here. (A person who must still choose a new password can only
// reach POST password, session and logout.)
Route::middleware('documentator.signed-in')->group(function () {
    Route::post('password', [PasswordController::class, 'change']);

    Route::middleware('documentator.can:documentator.document.view')->group(function () {
        Route::get('documents', [DocumentController::class, 'index']);
        Route::get('documents/{document}', [DocumentController::class, 'show'])->whereNumber('document');
    });
    Route::middleware('documentator.can:documentator.document.create')->group(function () {
        Route::get('documents/suggest-code', [DocumentController::class, 'suggestCode']);
        Route::post('documents', [DocumentController::class, 'store']);
    });
    Route::patch('documents/{document}', [DocumentController::class, 'update'])
        ->whereNumber('document')->middleware('documentator.can:documentator.version.create');
    Route::post('documents/{document}/versions', [DocumentController::class, 'storeVersion'])
        ->whereNumber('document')->middleware('documentator.can:documentator.version.create');

    Route::get('files/{file}', [FileController::class, 'download'])
        ->whereNumber('file')->middleware('documentator.can:documentator.file.download');

    Route::get('audits', [AuditController::class, 'index'])->middleware('documentator.can:documentator.audit.view');

    // archiving a department's versions: needs the archive tag in some department (the controller checks which)
    Route::middleware('documentator.can:documentator.version.archive')->group(function () {
        Route::get('archive', [ArchiveController::class, 'index']);
        Route::post('versions/{version}/archive', [ArchiveController::class, 'archive'])->whereNumber('version');
        Route::post('versions/{version}/restore', [ArchiveController::class, 'restore'])->whereNumber('version');
    });

    // the archive rules work on every department at once: the Administrator's alone
    Route::middleware('documentator.can:documentator.archive.manage')->group(function () {
        Route::get('archive/rules', [ArchiveController::class, 'rules']);
        Route::put('archive/rules/{kind}', [ArchiveController::class, 'saveRule'])->whereIn('kind', ['idle', 'old']);
        Route::post('archive/run', [ArchiveController::class, 'run']);
    });

    Route::middleware('documentator.can:documentator.role.manage')->group(function () {
        Route::get('roles', [RoleController::class, 'index']);
        Route::post('roles', [RoleController::class, 'store']);
        Route::patch('roles/{role}', [RoleController::class, 'update'])->whereNumber('role');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])->whereNumber('role');
    });

    Route::middleware('documentator.can:documentator.department.manage')->group(function () {
        Route::get('departments', [DepartmentController::class, 'index']);
        Route::post('departments', [DepartmentController::class, 'store']);
        Route::patch('departments/{department}', [DepartmentController::class, 'update'])->whereNumber('department');
    });

    Route::middleware('documentator.can:documentator.user.manage')->group(function () {
        Route::get('users', [UserController::class, 'index']);
        Route::post('users', [UserController::class, 'store']);
        Route::patch('users/{user}', [UserController::class, 'update'])->whereNumber('user');
        Route::post('users/{user}/password', [UserController::class, 'setPassword'])->whereNumber('user');
    });
});
