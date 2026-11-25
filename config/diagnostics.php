<?php

return [
    'enabled' => env('ERROR_DATA_ENABLED', env('APP_ENV') !== 'testing'),
    'directory' => env('ERROR_DATA_DIRECTORY', base_path('data error')),
    'max_bytes' => 5 * 1024 * 1024,
    'backups' => 3,
];
