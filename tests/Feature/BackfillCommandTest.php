<?php

// bump:backfill sends an app's history in batches of 100, marked as backfill, and reports what happened.

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use StickySoftware\Bump\Contracts\Backfill;
use StickySoftware\Bump\Tests\Fixtures\ListBackfill;
use StickySoftware\Bump\Tests\Fixtures\Team;

beforeEach(function () {
    Sleep::fake();
});

function orders(int $count): Generator
{
    foreach (range(1, $count) as $number) {
        yield ['id' => "order-{$number}", 'trackable' => 'orders', 'amount' => 10];
    }
}

it('sends in batches of 100, marked as backfill', function () {
    fakeApi();
    ListBackfill::$events = orders(250);

    $this->artisan('bump:backfill', ['class' => ListBackfill::class])->assertSuccessful();

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request) => $request->data()['backfill'] === true);

    $sizes = collect(Http::recorded())->map(fn (array $pair) => count($pair[0]->data()['events']))->all();
    expect($sizes)->toBe([100, 100, 50]);
});

it('counts list values toward a batch, like the API', function () {
    fakeApi();
    ListBackfill::$events = [
        ...iterator_to_array(orders(99)),
        ['id' => 'refunds', 'trackable' => 'refunds', 'amount' => [-5, -10]],
    ];

    $this->artisan('bump:backfill', ['class' => ListBackfill::class])->assertSuccessful();

    $sizes = collect(Http::recorded())->map(fn (array $pair) => count($pair[0]->data()['events']))->all();
    expect($sizes)->toBe([99, 1]);
});

it('sends the ids the app gives, as text', function () {
    fakeApi();
    ListBackfill::$events = [
        ['id' => 'order-1', 'trackable' => 'orders'],
        ['id' => 2, 'trackable' => 'orders'],
    ];

    $this->artisan('bump:backfill', ['class' => ListBackfill::class])->assertSuccessful();

    expect(array_column(sentEvents(), 'id'))->toBe(['order-1', '2']);
});

it('takes models and dates like track() does', function () {
    fakeApi();
    ListBackfill::$events = [[
        'id' => 'order-1',
        'trackable' => 'orders',
        'identifiable' => new Team(['id' => 8, 'name' => 'Acme BV']),
        'occurred_at' => Carbon::parse('2025-03-01 09:30:00', 'Europe/Amsterdam'),
    ]];

    $this->artisan('bump:backfill', ['class' => ListBackfill::class])->assertSuccessful();

    expect(sentEvents()[0])->toBe([
        'id' => 'order-1',
        'trackable' => 'orders',
        'identifiable' => ['handle' => 'team_8', 'label' => 'Acme BV'],
        'occurred_at' => '2025-03-01T09:30:00+01:00',
    ]);
});

it('rejects events without an id and sends the rest', function () {
    fakeApi();
    ListBackfill::$events = [
        ['id' => 'order-1', 'trackable' => 'orders'],
        ['trackable' => 'orders'],
        ['id' => '', 'trackable' => 'orders'],
    ];

    $this->artisan('bump:backfill', ['class' => ListBackfill::class])
        ->expectsTable(['Accepted', 'Duplicate', 'Rejected'], [[1, 0, 2]])
        ->expectsOutputToContain('2 × Backfill events need an id derived from your own data.')
        ->assertSuccessful();

    expect(sentEvents())->toHaveCount(1);
});

it('sums up accepted, duplicate and rejected events with the reasons', function () {
    Http::fake(['*' => Http::response(['object' => 'list', 'data' => [
        ['object' => 'event', 'id' => 'evt_1', 'status' => 'accepted'],
        ['object' => 'event', 'id' => 'evt_2', 'status' => 'duplicate'],
        ['object' => 'event', 'status' => 'rejected', 'error' => ['message' => 'Amounts take at most 2 decimals.', 'param' => 'events[2].amount']],
    ]])]);
    ListBackfill::$events = orders(3);

    $this->artisan('bump:backfill', ['class' => ListBackfill::class])
        ->expectsTable(['Accepted', 'Duplicate', 'Rejected'], [[1, 1, 1]])
        ->expectsOutputToContain('1 × Amounts take at most 2 decimals.')
        ->assertSuccessful();
});

it('waits out a rate limit and carries on', function () {
    Http::fake(['*' => Http::sequence()
        ->push(null, 429, ['Retry-After' => '60'])
        ->push(['object' => 'list', 'data' => [['object' => 'event', 'status' => 'accepted']]]),
    ]);
    ListBackfill::$events = orders(1);

    $this->artisan('bump:backfill', ['class' => ListBackfill::class])->assertSuccessful();

    Sleep::assertSequence([Sleep::for(60)->seconds()]);
});

it('stops at a batch the API refuses', function () {
    Http::fake(['*' => Http::response(['error' => ['code' => 'authentication_error', 'message' => 'Invalid token.']], 401)]);
    ListBackfill::$events = orders(150);

    $this->artisan('bump:backfill', ['class' => ListBackfill::class])
        ->expectsOutputToContain('Stopped')
        ->assertFailed();

    Http::assertSentCount(1);
});

it('refuses a class that is not a backfill', function () {
    $this->artisan('bump:backfill', ['class' => Team::class])
        ->expectsOutputToContain("doesn't implement ".Backfill::class)
        ->assertFailed();

    Http::assertNothingSent();
});

it('refuses to run without a token', function () {
    config(['bump.test_token' => null]);
    ListBackfill::$events = orders(1);

    $this->artisan('bump:backfill', ['class' => ListBackfill::class])
        ->expectsOutputToContain('BUMP_TEST_TOKEN is not set.')
        ->assertFailed();

    Http::assertNothingSent();
});

it('starts its summary from zero on a second run in the same process', function () {
    fakeApi();

    ListBackfill::$events = orders(2);
    $this->artisan('bump:backfill', ['class' => ListBackfill::class])->assertSuccessful();

    ListBackfill::$events = orders(1);
    $this->artisan('bump:backfill', ['class' => ListBackfill::class])
        ->expectsTable(['Accepted', 'Duplicate', 'Rejected'], [[1, 0, 0]])
        ->assertSuccessful();
});
