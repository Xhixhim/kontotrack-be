<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Display timezone
    |--------------------------------------------------------------------------
    |
    | Times are stored in UTC. Printed documents and exports show them in this
    | timezone. Kosovo uses the IANA zone Europe/Belgrade.
    |
    */

    'display_timezone' => env('KONTOTRACK_DISPLAY_TIMEZONE', 'Europe/Belgrade'),

    /*
    |--------------------------------------------------------------------------
    | Backups
    |--------------------------------------------------------------------------
    |
    | Backups contain the full database and every uploaded business document.
    | Keep the directory outside the web root. When a password is set, every
    | file in the archive is encrypted with AES-256.
    |
    */

    'backups' => [
        'path' => env('KONTOTRACK_BACKUP_PATH', storage_path('app/backups')),
        'keep' => (int) env('KONTOTRACK_BACKUP_KEEP', 14),
        'password' => env('KONTOTRACK_BACKUP_PASSWORD'),
        'documents_disk' => 'local',
        'documents_directory' => 'businesses',
    ],

];
