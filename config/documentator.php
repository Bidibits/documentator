<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Documentator
    |--------------------------------------------------------------------------
    |
    | Idle sign-out is SESSION_LIFETIME (minutes); the absolute limit below applies on top of it.
    |
    */

    // the page scripts/build-app.mjs builds from resources/app (npm run build)
    'app_page' => resource_path('app/build/index.html'),

    'session_max_days' => (int) env('DOCUMENTATOR_SESSION_MAX_DAYS', 7),

    // wrong passwords lock the username, and separately the visitor's address, for this long
    'login' => [
        'user_attempts' => 5,
        'ip_attempts' => 25,
        'lock_minutes' => 15,
    ],

];
