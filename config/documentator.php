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

    // what a new document's suggested code starts with (a suggestion only: the person may type any code)
    'code_prefix' => env('DOCUMENTATOR_CODE_PREFIX', 'TSI'),

    // the office's time zone: the date in a suggested code is today's date here
    'timezone' => env('DOCUMENTATOR_TIMEZONE', 'Asia/Manila'),

    'uploads' => [
        'max_files' => 10,
        'max_kilobytes' => 25 * 1024,
        // checked against what the file really is, not what its name says
        'types' => [
            'application/pdf' => ['pdf'],
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
            'image/png' => ['png'],
            'image/jpeg' => ['jpg', 'jpeg'],
        ],
    ],

    'session_max_days' => (int) env('DOCUMENTATOR_SESSION_MAX_DAYS', 7),

    // wrong passwords lock the username, and separately the visitor's address, for this long
    'login' => [
        'user_attempts' => 5,
        'ip_attempts' => 25,
        'lock_minutes' => 15,
    ],

];
