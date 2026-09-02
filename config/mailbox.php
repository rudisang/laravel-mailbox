<?php

declare(strict_types=1);

return [
    // null = decide by environment; false = force off. There is no way to force ON outside allowed environments.
    'enabled' => env('MAILBOX_ENABLED'),

    // Environments in which the transport captures and the UI is reachable. 'production' is always refused.
    'environments' => ['local', 'testing'],

    // URL prefix of the mailbox UI.
    'path' => env('MAILBOX_PATH', '_mailbox'),

    // Middleware applied to every mailbox route (the package adds its own Authorize middleware).
    'middleware' => ['web'],

    // Private storage root. null = storage/framework/mailbox
    'storage_path' => env('MAILBOX_STORAGE_PATH'),

    // Optional test-run namespace attached to every capture in this process.
    'namespace' => env('MAILBOX_NAMESPACE'),

    'retention' => [
        'days' => 7,
        'max_messages' => 1000,
        'max_bytes' => 250 * 1024 * 1024,
    ],

    'limits' => [
        'raw_bytes' => 50 * 1024 * 1024,
        'parts' => 100,
        'depth' => 30,
        'header_bytes' => 256 * 1024,
        'search_text_bytes' => 512 * 1024,
        'preview_bytes' => 2 * 1024 * 1024,
    ],
];
