<?php

return [

    // Off means every call is accepted and dropped, without a log line.
    'enabled' => env('BUMP_ENABLED', true),

    // APP_ENV=production sends with the live token, every other environment with the test token.
    // Without the token for the current environment, calls are accepted and dropped.
    'live_token' => env('BUMP_LIVE_TOKEN'),
    'test_token' => env('BUMP_TEST_TOKEN'),

    'url' => env('BUMP_URL', 'https://api.bumpbunny.dev/v1'),

    // "queue" sends from a queued job, "sync" sends over HTTP while the request or command terminates.
    'delivery' => env('BUMP_DELIVERY', 'queue'),

    // Seconds a sync send may take. Sync mode sends while the request terminates, so a slow answer holds
    // a PHP worker; queued sends keep the HTTP client's own default. Slow answers are logged.
    'sync_timeout' => (int) env('BUMP_SYNC_TIMEOUT', 5),

    // Null uses the app's default connection and queue.
    'queue' => [
        'connection' => env('BUMP_QUEUE_CONNECTION'),
        'name' => env('BUMP_QUEUE'),
    ],

];
