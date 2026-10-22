<?php

// bump:backfill sends an app's history from before live counting started, in batches of 100, marked
// as backfill, one class after another, and reports what happened.

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use StickySoftware\Bump\Contracts\Backfill;
use StickySoftware\Bump\Tests\Fixtures\ListBackfill;
use StickySoftware\Bump\Tests\Fixtures\OtherListBackfill;
use StickySoftware\Bump\Tests\Fixtures\Team;

beforeEach(function () {
    Sleep::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00:00', 'UTC'));

    ListBackfill::$events = [];
    ListBackfill::$window = null;
    OtherListBackfill::$events = [];
});

function orders(int $count, string $prefix = 'order'): Generator
{
    foreach (range(1, $count) as $number) {
        yield ['id' => "{$prefix}-{$number}", 'trackable' => 'orders', 'amount' => 10, 'occurred_at' => '2026-09-15T10:00:00+00:00'];
    }
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function backfill(array $overrides = []): array
{
    return ['class' => [ListBackfill::class], '--until' => '2026-10-01 00:00:00', '--force' => true, ...$overrides];
}

it('sends in batches of 100, marked as backfill', function () {
    fakeApi();
    ListBackfill::$events = orders(250);

    $this->artisan('bump:backfill', backfill())->assertSuccessful();

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request) => $request->data()['backfill'] === true);

    $sizes = collect(Http::recorded())->map(fn (array $pair) => count($pair[0]->data()['events']))->all();
    expect($sizes)->toBe([100, 100, 50]);
});

it('counts list values toward a batch, like the API', function () {
    fakeApi();
    ListBackfill::$events = [
        ...iterator_to_array(orders(99)),
        ['id' => 'refunds', 'trackable' => 'refunds', 'amount' => [-5, -10], 'occurred_at' => '2026-09-15T10:00:00+00:00'],
    ];

    $this->artisan('bump:backfill', backfill())->assertSuccessful();

    $sizes = collect(Http::recorded())->map(fn (array $pair) => count($pair[0]->data()['events']))->all();
    expect($sizes)->toBe([99, 1]);
});

it('sends the ids the app gives, as text', function () {
    fakeApi();
    ListBackfill::$events = [
        ['id' => 'order-1', 'trackable' => 'orders', 'occurred_at' => '2026-09-15T10:00:00+00:00'],
        ['id' => 2, 'trackable' => 'orders', 'occurred_at' => '2026-09-15T10:00:00+00:00'],
    ];

    $this->artisan('bump:backfill', backfill())->assertSuccessful();

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

    $this->artisan('bump:backfill', backfill())->assertSuccessful();

    expect(sentEvents()[0])->toBe([
        'id' => 'order-1',
        'trackable' => 'orders',
        'identifiable' => ['handle' => 'team_8', 'label' => 'Acme BV'],
        'occurred_at' => '2025-03-01T09:30:00+01:00',
    ]);
});

it('sends amount and cents as amounts with two decimals', function () {
    fakeApi();
    ListBackfill::$events = [
        ['id' => 'float', 'trackable' => 'orders', 'amount' => 10.5, 'occurred_at' => '2026-09-15T10:00:00+00:00'],
        ['id' => 'decimal-column', 'trackable' => 'orders', 'amount' => '49.9900', 'occurred_at' => '2026-09-15T10:00:00+00:00'],
        ['id' => 'int', 'trackable' => 'orders', 'amount' => 20, 'occurred_at' => '2026-09-15T10:00:00+00:00'],
        ['id' => 'cents', 'trackable' => 'orders', 'cents' => 4999, 'occurred_at' => '2026-09-15T10:00:00+00:00'],
        ['id' => 'cents-list', 'trackable' => 'refunds', 'cents' => [-500, -5], 'occurred_at' => '2026-09-15T10:00:00+00:00'],
    ];

    $this->artisan('bump:backfill', backfill())->assertSuccessful();

    expect(array_column(sentEvents(), 'amount', 'id'))->toBe([
        'float' => '10.50',
        'decimal-column' => '49.99',
        'int' => 20,
        'cents' => '49.99',
        'cents-list' => ['-5.00', '-0.05'],
    ])->and(array_filter(sentEvents(), fn (array $event) => array_key_exists('cents', $event)))->toBe([]);
});

it('rejects events with an amount Bump would have to round, or both amount and cents', function () {
    fakeApi();
    ListBackfill::$events = [
        ['id' => 'order-1', 'trackable' => 'orders', 'amount' => '10.00', 'occurred_at' => '2026-09-15T10:00:00+00:00'],
        ['id' => 'order-2', 'trackable' => 'orders', 'amount' => 10.005, 'occurred_at' => '2026-09-15T10:00:00+00:00'],
        ['id' => 'order-3', 'trackable' => 'orders', 'amount' => 10.0, 'cents' => 1000, 'occurred_at' => '2026-09-15T10:00:00+00:00'],
        ['id' => 'order-4', 'trackable' => 'orders', 'cents' => '1000', 'occurred_at' => '2026-09-15T10:00:00+00:00'],
        ['id' => 'order-5', 'trackable' => 'orders', 'count' => [1, 1], 'cents' => [500, 500], 'occurred_at' => '2026-09-15T10:00:00+00:00'],
    ];

    $this->artisan('bump:backfill', backfill())
        ->expectsTable(['Accepted', 'Duplicate', 'Rejected'], [[1, 0, 4]])
        ->expectsOutputToContain('1 × Amounts take at most 2 decimals, got 10.005.')
        ->expectsOutputToContain("1 × amount and cents can't both be given.")
        ->expectsOutputToContain('1 × cents takes whole numbers, got string.')
        ->expectsOutputToContain("1 × count and cents can't both be lists.")
        ->assertSuccessful();

    expect(array_column(sentEvents(), 'id'))->toBe(['order-1']);
});

it('counts a cents list toward a batch, like an amount list', function () {
    fakeApi();
    ListBackfill::$events = [
        ...iterator_to_array(orders(99)),
        ['id' => 'refunds', 'trackable' => 'refunds', 'cents' => [-500, -1000], 'occurred_at' => '2026-09-15T10:00:00+00:00'],
    ];

    $this->artisan('bump:backfill', backfill())->assertSuccessful();

    $sizes = collect(Http::recorded())->map(fn (array $pair) => count($pair[0]->data()['events']))->all();
    expect($sizes)->toBe([99, 1]);
});

it('rejects events without an id and sends the rest', function () {
    fakeApi();
    ListBackfill::$events = [
        ['id' => 'order-1', 'trackable' => 'orders', 'occurred_at' => '2026-09-15T10:00:00+00:00'],
        ['trackable' => 'orders'],
        ['id' => '', 'trackable' => 'orders'],
    ];

    $this->artisan('bump:backfill', backfill())
        ->expectsTable(['Accepted', 'Duplicate', 'Rejected'], [[1, 0, 2]])
        ->expectsOutputToContain('2 × Backfill events need an id derived from your own data.')
        ->assertSuccessful();

    expect(sentEvents())->toHaveCount(1);
});

it('rejects events from outside the window, so a class that forgets to constrain its query sends no overlap', function () {
    fakeApi();
    ListBackfill::$events = [
        ['id' => 'before-since', 'trackable' => 'orders', 'occurred_at' => CarbonImmutable::parse('2026-08-31 23:59:59')],
        ['id' => 'at-since', 'trackable' => 'orders', 'occurred_at' => CarbonImmutable::parse('2026-09-01 00:00:00')],
        ['id' => 'before-until', 'trackable' => 'orders', 'occurred_at' => CarbonImmutable::parse('2026-09-30 23:59:59')],
        ['id' => 'at-until', 'trackable' => 'orders', 'occurred_at' => CarbonImmutable::parse('2026-10-01 00:00:00')],
        ['id' => 'after-until-as-text', 'trackable' => 'orders', 'occurred_at' => '2026-10-02T09:00:00+00:00'],
    ];

    $this->artisan('bump:backfill', backfill(['--since' => '2026-09-01 00:00:00']))
        ->expectsTable(['Accepted', 'Duplicate', 'Rejected'], [[2, 0, 3]])
        ->expectsOutputToContain('3 × occurred_at outside the window.')
        ->assertSuccessful();

    expect(array_column(sentEvents(), 'id'))->toBe(['at-since', 'before-until']);
});

it('rejects events without occurred_at, which Bump would date now, past the window', function () {
    fakeApi();
    ListBackfill::$events = [
        ['id' => 'dated', 'trackable' => 'orders', 'occurred_at' => '2026-09-15T10:00:00+00:00'],
        ['id' => 'left-out', 'trackable' => 'orders'],
        ['id' => 'null', 'trackable' => 'orders', 'occurred_at' => null],
        ['id' => 'empty', 'trackable' => 'orders', 'occurred_at' => ''],
    ];

    $this->artisan('bump:backfill', backfill())
        ->expectsTable(['Accepted', 'Duplicate', 'Rejected'], [[1, 0, 3]])
        ->expectsOutputToContain('3 × occurred_at is required for a backfill.')
        ->assertSuccessful();

    expect(array_column(sentEvents(), 'id'))->toBe(['dated']);
});

it('sums up accepted, duplicate and rejected events with the reasons', function () {
    Http::fake(['*' => Http::response(['object' => 'list', 'data' => [
        ['object' => 'event', 'id' => 'evt_1', 'status' => 'accepted'],
        ['object' => 'event', 'id' => 'evt_2', 'status' => 'duplicate'],
        ['object' => 'event', 'status' => 'rejected', 'error' => ['message' => 'Amounts take at most 2 decimals.', 'param' => 'events[2].amount']],
    ]])]);
    ListBackfill::$events = orders(3);

    $this->artisan('bump:backfill', backfill())
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

    $this->artisan('bump:backfill', backfill())->assertSuccessful();

    Sleep::assertSequence([Sleep::for(60)->seconds()]);
});

it('stops at a batch the API refuses', function () {
    Http::fake(['*' => Http::response(['error' => ['code' => 'authentication_error', 'message' => 'Invalid token.']], 401)]);
    ListBackfill::$events = orders(150);

    $this->artisan('bump:backfill', backfill())
        ->expectsOutputToContain("Bump didn't take a batch")
        ->expectsOutputToContain('Stopped at '.ListBackfill::class.'. Run the same command again to carry on.')
        ->assertFailed();

    Http::assertSentCount(1);
});

it('runs several classes in the order given', function () {
    fakeApi();
    ListBackfill::$events = orders(2);
    OtherListBackfill::$events = orders(1, 'refund');

    $this->artisan('bump:backfill', backfill(['class' => [OtherListBackfill::class, ListBackfill::class]]))
        ->expectsOutputToContain(OtherListBackfill::class)
        ->expectsOutputToContain(ListBackfill::class)
        ->assertSuccessful();

    expect(array_column(sentEvents(), 'id'))->toBe(['refund-1', 'order-1', 'order-2']);
});

it('stops at the first class Bump does not take, and leaves the rest for a rerun', function () {
    Http::fake(['*' => Http::response(['error' => ['code' => 'authentication_error', 'message' => 'Invalid token.']], 401)]);
    ListBackfill::$events = orders(1);
    OtherListBackfill::$events = orders(1, 'refund');

    $this->artisan('bump:backfill', backfill(['class' => [ListBackfill::class, OtherListBackfill::class]]))
        ->expectsOutputToContain('Stopped at '.ListBackfill::class.'.')
        ->doesntExpectOutputToContain(OtherListBackfill::class)
        ->assertFailed();

    Http::assertSentCount(1);
});

it('carries on to the next class after rejected events', function () {
    fakeApi();
    ListBackfill::$events = [['trackable' => 'orders']];
    OtherListBackfill::$events = orders(1, 'refund');

    $this->artisan('bump:backfill', backfill(['class' => [ListBackfill::class, OtherListBackfill::class]]))
        ->assertSuccessful();

    expect(array_column(sentEvents(), 'id'))->toBe(['refund-1']);
});

it('refuses a class that is not a backfill before sending anything', function () {
    ListBackfill::$events = orders(1);

    $this->artisan('bump:backfill', backfill(['class' => [ListBackfill::class, Team::class]]))
        ->expectsOutputToContain("doesn't implement ".Backfill::class)
        ->assertFailed();

    Http::assertNothingSent();
});

it('refuses to run without a token', function () {
    config(['bump.test_token' => null]);
    ListBackfill::$events = orders(1);

    $this->artisan('bump:backfill', backfill())
        ->expectsOutputToContain('BUMP_TEST_TOKEN is not set.')
        ->assertFailed();

    Http::assertNothingSent();
});

it('refuses a plain http BUMP_URL before sending anything', function () {
    config(['bump.url' => 'http://api.bump.example']);
    ListBackfill::$events = orders(1);

    $this->artisan('bump:backfill', backfill())
        ->expectsOutputToContain('BUMP_URL has to use https')
        ->assertFailed();

    Http::assertNothingSent();
});

it('counts on a dry run with a plain http BUMP_URL', function () {
    config(['bump.url' => 'http://api.bump.example']);
    ListBackfill::$events = orders(2);

    $this->artisan('bump:backfill', backfill(['--dry-run' => true]))
        ->expectsOutputToContain('2 events')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('stops with a one-line reason when sending throws', function () {
    Http::fake(fn () => throw new RuntimeException("Something unexpected.\nWith a second line."));
    ListBackfill::$events = orders(1);

    $this->artisan('bump:backfill', backfill())
        ->expectsOutputToContain("Bump couldn't send a batch: Something unexpected.")
        ->doesntExpectOutputToContain('With a second line.')
        ->expectsOutputToContain('Stopped at '.ListBackfill::class.'. Run the same command again to carry on.')
        ->assertFailed();
});

it('rejects an event with text that is not valid UTF-8 and sends the rest', function () {
    fakeApi();
    ListBackfill::$events = [
        ...iterator_to_array(orders(1)),
        ['id' => 'order-2', 'trackable' => 'orders', 'note' => "caf\xE9", 'occurred_at' => '2026-09-15T10:00:00+00:00'],
        ['id' => 'order-3', 'trackable' => 'orders', "not\xFF" => 1, 'occurred_at' => '2026-09-15T10:00:00+00:00'],
    ];

    $this->artisan('bump:backfill', backfill())
        ->expectsOutputToContain("2 × The event has text that isn't valid UTF-8.")
        ->assertSuccessful();

    expect(array_column(sentEvents(), 'id'))->toBe(['order-1']);
});

it('starts its summary from zero on a second run in the same process', function () {
    fakeApi();

    ListBackfill::$events = orders(2);
    $this->artisan('bump:backfill', backfill())->assertSuccessful();

    ListBackfill::$events = orders(1);
    $this->artisan('bump:backfill', backfill())
        ->expectsTable(['Accepted', 'Duplicate', 'Rejected'], [[1, 0, 0]])
        ->assertSuccessful();
});

it('refuses to run without --until', function (array $until) {
    ListBackfill::$events = orders(1);

    $this->artisan('bump:backfill', [...backfill(), ...$until])
        ->expectsOutputToContain('--until is required: when live counting started. Events after it were already counted live, so Bump would count them twice.')
        ->assertFailed();

    Http::assertNothingSent();
})->with([
    'left out' => [['--until' => null]],
    'empty' => [['--until' => ' ']],
]);

it('refuses a time it cannot read', function (string $option) {
    $this->artisan('bump:backfill', backfill(["--{$option}" => 'last tuesday-ish']))
        ->expectsOutputToContain("Can't read --{$option}=\"last tuesday-ish\" as a time.")
        ->assertFailed();

    Http::assertNothingSent();
})->with(['until', 'since']);

it('refuses an --until in the future', function () {
    $this->artisan('bump:backfill', backfill(['--until' => '2026-10-16 12:00:01']))
        ->expectsOutputToContain('--until is in the future. Live counting has already started, so the cutoff is behind us.')
        ->assertFailed();

    Http::assertNothingSent();
});

it('refuses a --since that is not before --until', function (string $since) {
    $this->artisan('bump:backfill', backfill(['--since' => $since]))
        ->expectsOutputToContain('--since has to be before --until.')
        ->assertFailed();

    Http::assertNothingSent();
})->with(['at' => '2026-10-01 00:00:00', 'after' => '2026-10-02 00:00:00']);

it('asks before sending', function () {
    fakeApi();
    ListBackfill::$events = orders(1);

    $this->artisan('bump:backfill', backfill(['--force' => false]))
        ->expectsConfirmation('Send everything from the start until 2026-10-01 00:00:00 UTC to Bump?', 'yes')
        ->assertSuccessful();

    Http::assertSentCount(1);
});

it('names --since in the question', function () {
    $this->artisan('bump:backfill', backfill(['--force' => false, '--since' => '2026-09-01']))
        ->expectsConfirmation('Send everything from 2026-09-01 00:00:00 UTC until 2026-10-01 00:00:00 UTC to Bump?', 'no')
        ->expectsOutputToContain('Nothing sent.')
        ->assertFailed();

    Http::assertNothingSent();
});

it('sends without asking with --force', function () {
    fakeApi();
    ListBackfill::$events = orders(1);

    $this->artisan('bump:backfill', backfill(['--force' => true]))->assertSuccessful();

    Http::assertSentCount(1);
});

it('counts per class on a dry run, sends nothing and needs no token', function () {
    config(['bump.test_token' => null]);
    ListBackfill::$events = [
        ...iterator_to_array(orders(2)),
        ['id' => 'list', 'trackable' => 'orders', 'count' => [1, 2], 'occurred_at' => '2026-09-15T10:00:00+00:00'],
        ['id' => 'late', 'trackable' => 'orders', 'occurred_at' => CarbonImmutable::parse('2026-10-05')],
    ];
    OtherListBackfill::$events = orders(1, 'refund');

    $this->artisan('bump:backfill', backfill(['class' => [ListBackfill::class, OtherListBackfill::class], '--dry-run' => true, '--force' => false]))
        ->expectsOutputToContain('4 events')
        ->expectsOutputToContain('1 × occurred_at outside the window.')
        ->expectsOutputToContain('1 event')
        ->expectsOutputToContain('5 events')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('reads times in the app timezone and gives the class bounds that match its wall-clock columns', function () {
    config(['app.timezone' => 'Europe/Amsterdam']);
    fakeApi();
    ListBackfill::$events = [
        ['id' => 'before', 'trackable' => 'orders', 'occurred_at' => CarbonImmutable::parse('2026-02-28 23:15:00', 'UTC')],
        // 01:15 in Amsterdam: after the cutoff, though its UTC clock reads earlier than 00:30.
        ['id' => 'after', 'trackable' => 'orders', 'occurred_at' => CarbonImmutable::parse('2026-03-01 00:15:00', 'UTC')],
    ];

    $this->artisan('bump:backfill', backfill(['--until' => '2026-03-01 00:30']))
        ->expectsOutputToContain('2026-03-01 00:30:00')
        ->expectsOutputToContain('2026-02-28 23:30:00')
        ->expectsOutputToContain('1 × occurred_at outside the window.')
        ->assertSuccessful();

    expect(array_column(sentEvents(), 'id'))->toBe(['before'])
        ->and(ListBackfill::$window->until->tzName)->toBe('Europe/Amsterdam')
        ->and(DB::connection()->prepareBindings(ListBackfill::$window->constrain(DB::table('orders'), 'paid_at')->getBindings()))
        ->toBe(['2026-03-01 00:30:00']);
});

it('takes the copied Tracking since with its own offset, whatever the app timezone', function () {
    config(['app.timezone' => 'America/New_York']);
    fakeApi();
    ListBackfill::$events = [
        ['id' => 'before', 'trackable' => 'orders', 'occurred_at' => CarbonImmutable::parse('2026-10-15 12:02:10', 'UTC')],
        ['id' => 'at', 'trackable' => 'orders', 'occurred_at' => CarbonImmutable::parse('2026-10-15 12:02:11', 'UTC')],
    ];

    $this->artisan('bump:backfill', backfill(['--until' => '2026-10-15T14:02:11+02:00']))
        ->expectsOutputToContain('2026-10-15 08:02:11')
        ->expectsOutputToContain('2026-10-15 12:02:11')
        ->expectsOutputToContain('1 × occurred_at outside the window.')
        ->assertSuccessful();

    expect(array_column(sentEvents(), 'id'))->toBe(['before'])
        ->and(ListBackfill::$window->until->tzName)->toBe('America/New_York')
        ->and(DB::connection()->prepareBindings(ListBackfill::$window->constrain(DB::table('orders'), 'paid_at')->getBindings()))
        ->toBe(['2026-10-15 08:02:11']);
});
