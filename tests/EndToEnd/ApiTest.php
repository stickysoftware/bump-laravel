<?php

// Real requests to a running Bump API. Skipped by default; run with a test-environment token:
// BUMP_E2E_TOKEN=bump_test_… vendor/bin/pest --group=end-to-end
// BUMP_E2E_URL defaults to the local app, https://api.bump.test/v1.

use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use StickySoftware\Bump\Facades\Bump;
use StickySoftware\Bump\Tests\Fixtures\ListBackfill;
use StickySoftware\Bump\Tests\Fixtures\Team;

beforeEach(function () {
    if (! getenv('BUMP_E2E_TOKEN')) {
        $this->markTestSkipped('Set BUMP_E2E_TOKEN to a bump_test_… token.');
    }

    Http::allowStrayRequests();
    config([
        'bump.test_token' => getenv('BUMP_E2E_TOKEN'),
        'bump.url' => getenv('BUMP_E2E_URL') ?: 'https://api.bump.test/v1',
        'bump.delivery' => 'sync',
    ]);

    $this->responses = [];
    Event::listen(ResponseReceived::class, function (ResponseReceived $event) {
        $this->responses[] = $event->response->json();
        fwrite(STDOUT, "\n".$event->response->status().' '.$event->response->body()."\n");
    });
});

it('tracks and adjusts', function () {
    Bump::track('c7_e2e', identifiable: [new Team(['id' => 7001, 'name' => 'C7 test team']), 'c7_user_1'], amount: 49.99);
    Bump::track('c7_e2e', identifiable: 'c7_user_1', count: [1, 2]);
    Bump::adjust('c7_e2e', identifiable: 'c7_user_1', amount: -25.00, note: 'partial refund');
    Bump::flush();

    expect($this->responses)->toHaveCount(1)
        ->and(array_column($this->responses[0]['data'], 'status'))->toBe(['accepted', 'accepted', 'accepted', 'accepted']);
})->group('end-to-end');

it('backfills, and a rerun only finds duplicates', function () {
    $events = fn () => [
        ['id' => 'c7-e2e-order-1', 'trackable' => 'c7_e2e_backfill', 'identifiable' => 'c7_user_1', 'amount' => 10, 'occurred_at' => now()->subDays(40)],
        ['id' => 'c7-e2e-order-2', 'trackable' => 'c7_e2e_backfill', 'identifiable' => 'c7_user_2', 'amount' => 20, 'occurred_at' => now()->subDays(20)],
        ['id' => 'c7-e2e-order-3', 'trackable' => 'c7_e2e_backfill', 'amount' => 30.5, 'occurred_at' => now()->subDays(2)],
    ];

    ListBackfill::$events = $events();
    $this->artisan('bump:backfill', ['class' => [ListBackfill::class], '--until' => now()->subDay()->toDateTimeString(), '--force' => true])->assertSuccessful();

    ListBackfill::$events = $events();
    $this->artisan('bump:backfill', ['class' => [ListBackfill::class], '--until' => now()->subDay()->toDateTimeString(), '--force' => true])
        ->expectsTable(['Accepted', 'Duplicate', 'Rejected'], [[0, 3, 0]])
        ->assertSuccessful();
})->group('end-to-end');

it('logs a refused token instead of throwing', function () {
    config(['bump.test_token' => 'bump_test_not_a_real_token']);

    Bump::track('c7_e2e');
    Bump::flush();

    expect($this->responses[0]['error']['type'])->toBe('authentication_error');
})->group('end-to-end');
