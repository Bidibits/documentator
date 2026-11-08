<?php

// what a detector may report for the old Office formats, and for the new ones (zip files with Office parts inside)
$ole = ['application/msword', 'application/vnd.ms-excel', 'application/vnd.ms-powerpoint', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/CDFV2'];
$ooxml = ['application/zip', 'application/x-zip', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'];

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
        // By extension: the type a file is stored and downloaded as, and what the server may detect inside it. A name
        // alone is never enough: the content must be one of these. The old Office formats (doc, xls, ppt) share one
        // container, and the new ones (docx, xlsx, pptx) are zip files, so a detector can only say that much.
        'types' => [
            'pdf' => ['application/pdf', ['application/pdf']],
            'doc' => ['application/msword', $ole],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', $ooxml],
            'xls' => ['application/vnd.ms-excel', $ole],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $ooxml],
            'ppt' => ['application/vnd.ms-powerpoint', $ole],
            'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', $ooxml],
            'csv' => ['text/csv', ['text/csv', 'text/plain', 'application/csv']],
            'txt' => ['text/plain', ['text/plain']],
            'png' => ['image/png', ['image/png']],
            'jpg' => ['image/jpeg', ['image/jpeg']],
            'jpeg' => ['image/jpeg', ['image/jpeg']],
        ],
    ],

    // (Roles and what each may do in each department are records, kept in Administration > Roles.)

    'session_max_days' => (int) env('DOCUMENTATOR_SESSION_MAX_DAYS', 7),

    // wrong passwords lock the username, and separately the visitor's address, for this long
    'login' => [
        'user_attempts' => 5,
        'ip_attempts' => 25,
        'lock_minutes' => 15,
    ],

];
