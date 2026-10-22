<?php

// Real requests to the Bump API. Skipped by default; run with a test-environment token:
// BUMP_E2E_TOKEN=bump_test_… vendor/bin/pest --group=end-to-end
// BUMP_E2E_URL defaults to https://api.bumpbunny.dev.

use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use StickySoftware\Bump\Exceptions\BumpException;
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
        'bump.url' => getenv('BUMP_E2E_URL') ?: 'https://api.bumpbunny.dev',
        'bump.delivery' => 'sync',
    ]);

    $this->responses = [];
    Event::listen(ResponseReceived::class, function (ResponseReceived $event) {
        $this->responses[] = [$event->response->status(), $event->response->json()];
        fwrite(STDOUT, "\n".$event->response->status().' '.$event->response->body()."\n");
    });
});

it('tracks and adjusts', function () {
    $id = 'e2e-'.Str::lower(Str::random(12));

    Bump::track('e2e', identifiable: [new Team(['id' => 9001, 'name' => 'E2E test team']), 'e2e_user_1'], amount: 49.99, id: $id);
    Bump::track('e2e', identifiable: 'e2e_user_1', count: [1, 2]);
    Bump::adjust('e2e', identifiable: 'e2e_user_1', note: 'partial refund', cents: -2500);
    Bump::track('e2e', identifiable: 'e2e_user_1', amount: 49.99, id: $id);
    Bump::flush();

    expect($this->responses)->toHaveCount(1)
        ->and(array_column($this->responses[0][1]['data'], 'status'))->toBe(['accepted', 'accepted', 'accepted', 'accepted', 'duplicate']);
})->group('end-to-end');

it('backfills, and a rerun only finds duplicates', function () {
    $events = fn () => [
        ['id' => 'e2e-order-1', 'trackable' => 'e2e_backfill', 'identifiable' => 'e2e_user_1', 'amount' => 10.0, 'occurred_at' => now()->subDays(40)],
        ['id' => 'e2e-order-2', 'trackable' => 'e2e_backfill', 'identifiable' => 'e2e_user_2', 'cents' => 2000, 'occurred_at' => now()->subDays(20)],
        ['id' => 'e2e-order-3', 'trackable' => 'e2e_backfill', 'amount' => 30.5, 'occurred_at' => now()->subDays(2)],
    ];

    ListBackfill::$events = $events();
    $this->artisan('bump:backfill', ['class' => [ListBackfill::class], '--until' => now()->subDay()->toDateTimeString(), '--force' => true])->assertSuccessful();

    ListBackfill::$events = $events();
    $this->artisan('bump:backfill', ['class' => [ListBackfill::class], '--until' => now()->subDay()->toDateTimeString(), '--force' => true])
        ->expectsTable(['Accepted', 'Duplicate', 'Rejected'], [[0, 3, 0]])
        ->assertSuccessful();
})->group('end-to-end');

it('forgets an identifiable after sending its events', function () {
    $handle = 'e2e_forget_'.Str::lower(Str::random(12));

    Bump::track('e2e', identifiable: $handle);
    Bump::forget($handle);

    [$status, $body] = end($this->responses);

    if ($status === 403) {
        $this->markTestSkipped('The token can\'t forget identifiables.');
    }

    expect(array_column($this->responses, 0))->toBe([200, 200])
        ->and($body['forgotten'])->toBeTrue();
})->group('end-to-end');

it('reads the totals of a trackable', function () {
    Bump::track('e2e', amount: 1.5);
    Bump::flush();

    try {
        $total = Bump::total('e2e');
    } catch (BumpException $e) {
        if ($e->errorCode === 'missing_ability') {
            $this->markTestSkipped('The token can\'t read totals.');
        }

        throw $e;
    }

    expect($total->handle)->toBe('e2e')
        ->and($total->count)->toBeGreaterThan(0)
        ->and($total->amount)->toMatch('/^-?\d+\.\d{2}$/')
        ->and($total->cents)->toBe((int) str_replace('.', '', $total->amount))
        ->and($total->currency)->not->toBe('')
        ->and($total->lastEventAt)->not->toBeNull();
})->group('end-to-end');

it('logs a refused token instead of throwing', function () {
    config(['bump.test_token' => 'bump_test_not_a_real_token']);

    Bump::track('e2e');
    Bump::flush();

    expect($this->responses[0][1]['error']['type'])->toBe('authentication_error');
})->group('end-to-end');
