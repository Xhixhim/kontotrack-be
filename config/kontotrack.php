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
    | Web app address
    |--------------------------------------------------------------------------
    |
    | Links in emails (choose a password, reset a password) open the web app
    | at this address.
    |
    */

    'frontend_url' => env('KONTOTRACK_FRONTEND_URL', 'http://localhost:5173'),

    /*
    |--------------------------------------------------------------------------
    | AI features (optional)
    |--------------------------------------------------------------------------
    |
    | Reading invoices automatically and the assistant use Claude through
    | Anthropic's API. They stay off until ANTHROPIC_API_KEY is set, because
    | turning them on sends document contents and business figures to
    | Anthropic. Nothing read or answered by AI changes the books by itself.
    |
    */

    'ai' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('KONTOTRACK_AI_MODEL', 'claude-opus-5-5'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Encrypted employee data
    |--------------------------------------------------------------------------
    |
    | Employee personal numbers and IBANs are encrypted with APP_KEY. A keyed
    | hash of the personal number (a "blind index") still lets the app find
    | duplicates. Set a separate, permanent key here so rotating APP_KEY does
    | not change the hashes; without it APP_KEY is used, and after rotating
    | APP_KEY (keep the old one in APP_PREVIOUS_KEYS) you must run
    | php artisan kontotrack:reencrypt-employee-data.
    |
    | Backups hold the encrypted values: keep APP_KEY (and this key) safely
    | outside the backups, or a restored database cannot be read.
    |
    */

    'blind_index_key' => env('KONTOTRACK_BLIND_INDEX_KEY'),

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
