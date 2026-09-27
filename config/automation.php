<?php

declare(strict_types=1);

return [
    'app_version' => env('SOUNDMATIC_APP_VERSION', '1.1.54'),
    'update_repository' => env('SOUNDMATIC_UPDATE_REPOSITORY', 'fachry2204/soundonmatic'),
    'worker_url' => env('PLAYWRIGHT_SERVICE_URL', 'http://127.0.0.1:3100'),
    'local_server_url' => env('APP_URL', 'http://127.0.0.1:8000'),
    'node_binary' => env('NODE_BINARY', is_file(base_path('runtime/node/node.exe'))
        ? base_path('runtime/node/node.exe')
        : (PHP_OS_FAMILY === 'Windows' ? 'C:\\Program Files\\nodejs\\node.exe' : 'node')),
    'hmac_key' => env('AUTOMATION_HMAC_KEY') ?: hash('sha256', (string) env('APP_KEY').'|soundonmatic-automation-hmac'),
    'batch_size' => (int) env('AUTOMATION_BATCH_SIZE', 10),
    // Two isolated Chromium contexts may process two releases concurrently.
    'browser_concurrency' => max(1, (int) env('AUTOMATION_BROWSER_CONCURRENCY', 2)),
    'release_queue_workers' => max(1, (int) env('AUTOMATION_RELEASE_QUEUE_WORKERS', 2)),
    // A failed remote request still clears the cached state and authenticates on
    // retry, so healthy sessions do not need a fresh browser validation per job.
    'session_validation_interval_seconds' => (int) env('AUTOMATION_SESSION_VALIDATION_INTERVAL_SECONDS', 1800),
    'schedule_enabled' => (bool) env('AUTOMATION_SCHEDULE_ENABLED', false),
    // Planned Release Date from Soundfresh/SoundOn is interpreted in the
    // operating market timezone, not UTC, to avoid changing the day at 00:00.
    'release_timezone' => env('RELEASE_TIMEZONE', 'Asia/Bangkok'),
    'asset_allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('AUTOMATION_ASSET_ALLOWED_HOSTS', 'cms.soundfresh.id'))))),
    'soundfresh' => [
        'base_url' => env('SOUNDFRESH_BASE_URL', 'https://cms.soundfresh.id'),
        'releases_url' => env('SOUNDFRESH_RELEASES_URL', 'https://cms.soundfresh.id/admin/releases'),
    ],
    'soundon' => ['base_url' => env('SOUNDON_BASE_URL', 'https://www.soundon.global')],
];
