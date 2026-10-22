<?php

return [

    // Off drops every call without a log line. Off by default under APP_ENV=testing.
    'enabled' => env('BUMP_ENABLED', env('APP_ENV') !== 'testing'),

    // APP_ENV=production sends with the live token, every other environment with the test token.
    // Without the token for the current environment, calls are dropped.
    'live_token' => env('BUMP_LIVE_TOKEN'),
    'test_token' => env('BUMP_TEST_TOKEN'),

    // The host only: the package adds /v1.
    'url' => env('BUMP_URL', 'https://api.bumpbunny.dev'),

    // "queue" sends from a queued job, "sync" sends over HTTP while the request or command terminates.
    'delivery' => env('BUMP_DELIVERY', 'queue'),

    // Seconds a sync request or a read of totals may take. Sync answers that take half of it or more are logged.
    'sync_timeout' => (int) env('BUMP_SYNC_TIMEOUT', 5),

    // "prefixed_id" uses a model's spatie/laravel-prefixed-ids ID when it has one, "key" always uses
    // type plus key (user_12). Changing this after events were sent gives every model a new handle.
    'handles' => env('BUMP_HANDLES', 'prefixed_id'),

    // Null uses the app's default connection and queue.
    'queue' => [
        'connection' => env('BUMP_QUEUE_CONNECTION'),
        'name' => env('BUMP_QUEUE'),
    ],

];
