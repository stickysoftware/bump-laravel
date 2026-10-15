<?php

// What a call puts in the request body, which token sends it, and when nothing is sent at all.

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use StickySoftware\Bump\Facades\Bump;
use StickySoftware\Bump\Tests\Fixtures\Shop;
use StickySoftware\Bump\Tests\Fixtures\Team;
use StickySoftware\Bump\Tests\Fixtures\TeamMember;
use StickySoftware\Bump\Tests\Fixtures\User;

beforeEach(function () {
    config(['bump.delivery' => 'sync']);
    fakeApi();
    inWebRequest();
});

afterEach(function () {
    Relation::morphMap([], merge: false);
});

it('sends only what the call passes, plus an id and the time', function () {
    Carbon::setTestNow('2026-10-15 12:00:00');

    Bump::track('orders');
    Bump::flush();

    $event = sentEvents()[0];

    expect($event)->toHaveKeys(['id', 'trackable', 'occurred_at'])
        ->and(array_keys($event))->toHaveCount(3)
        ->and(Str::isUuid($event['id']))->toBeTrue()
        ->and($event['trackable'])->toBe('orders')
        ->and($event['occurred_at'])->toBe('2026-10-15T12:00:00+00:00');
});

it('sends every argument the call passes', function () {
    Bump::track(
        'orders',
        identifiable: 'usr_1',
        count: [1, 2],
        amount: 49.99,
        occurredAt: Carbon::parse('2026-10-02T14:03:11+02:00'),
        note: 'first order',
        activity: true,
    );
    Bump::flush();

    expect(sentEvents()[0])->toMatchArray([
        'trackable' => 'orders',
        'identifiable' => 'usr_1',
        'count' => [1, 2],
        'amount' => 49.99,
        'occurred_at' => '2026-10-02T14:03:11+02:00',
        'note' => 'first order',
        'activity' => true,
    ]);
});

it('wraps the events with backfill off', function () {
    Bump::track('orders');
    Bump::flush();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.bump.example/v1/events'
        && $request->method() === 'POST'
        && $request->data()['backfill'] === false
        && count($request->data()['events']) === 1);
});

it('gives every event its own id', function () {
    Bump::track('orders');
    Bump::track('orders');
    Bump::flush();

    expect(sentEvents()[0]['id'])->not->toBe(sentEvents()[1]['id']);
});

it('takes the time when the call happens, not when the buffer is sent', function () {
    Carbon::setTestNow('2026-10-15 12:00:00');
    Bump::track('orders');

    Carbon::setTestNow('2026-10-15 12:05:00');
    Bump::flush();

    expect(sentEvents()[0]['occurred_at'])->toBe('2026-10-15T12:00:00+00:00');
});

it('defaults adjust to no count and no activity', function () {
    Bump::adjust('orders', identifiable: 'usr_1', amount: -25.00, note: 'refund');
    Bump::flush();

    expect(sentEvents()[0])->toMatchArray([
        'count' => 0,
        'amount' => -25.0,
        'activity' => false,
        'note' => 'refund',
    ]);
});

it('lets adjust override its defaults', function () {
    Bump::adjust('members', count: -1, activity: true);
    Bump::flush();

    expect(sentEvents()[0])->toMatchArray(['count' => -1, 'activity' => true]);
});

it('sends with the live token in production', function () {
    app()['env'] = 'production';

    Bump::track('orders');
    Bump::flush();

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer bump_live_secret'));
});

it('sends with the test token everywhere else', function (string $environment) {
    app()['env'] = $environment;

    Bump::track('orders');
    Bump::flush();

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer bump_test_secret'));
})->with(['local', 'staging', 'testing']);

it('turns itself off without a token for the environment, logging that once', function () {
    config(['bump.test_token' => null]);
    Log::spy();

    Bump::track('orders');
    Bump::track('orders');
    Bump::flush();

    Http::assertNothingSent();
    Log::shouldHaveReceived('debug')->once()->with('Bump is off: BUMP_TEST_TOKEN is not set.');
});

it('needs the live token in production even when a test token is set', function () {
    app()['env'] = 'production';
    config(['bump.live_token' => '']);

    Bump::track('orders');
    Bump::flush();

    Http::assertNothingSent();
});

it('drops calls quietly when turned off', function () {
    config(['bump.enabled' => false]);
    Log::spy();

    Bump::track('orders');
    Bump::flush();

    Http::assertNothingSent();
    Log::shouldNotHaveReceived('debug');
});

describe('identifiables', function () {
    it('passes handles, keys and maps through untouched', function (mixed $identifiable) {
        Bump::track('orders', identifiable: $identifiable);
        Bump::flush();

        expect(sentEvents()[0]['identifiable'])->toBe($identifiable);
    })->with([
        'a handle' => 'usr_A ',
        'an integer key' => 42,
        'a label map' => [['team_8' => 'Acme BV']],
    ]);

    it('names a model after its class and key', function () {
        Bump::track('orders', identifiable: new User(['id' => 12]));
        Bump::track('orders', identifiable: new TeamMember(['id' => 3]));
        Bump::flush();

        expect(sentEvents()[0]['identifiable'])->toBe('user_12')
            ->and(sentEvents()[1]['identifiable'])->toBe('team_member_3');
    });

    it('prefers the morph map alias', function () {
        Relation::morphMap(['customer' => User::class]);

        Bump::track('orders', identifiable: new User(['id' => 12]));
        Bump::flush();

        expect(sentEvents()[0]['identifiable'])->toBe('customer_12');
    });

    it('uses bumpHandle() when the model has one', function () {
        Bump::track('orders', identifiable: new Shop(['id' => 1, 'slug' => 'acme']));
        Bump::flush();

        expect(sentEvents()[0]['identifiable'])->toBe('shop_acme');
    });

    it('sends a label only from bumpLabel()', function () {
        Bump::track('orders', identifiable: new Team(['id' => 8, 'name' => 'Acme BV']));
        Bump::track('orders', identifiable: new Team(['id' => 9, 'name' => null]));
        Bump::flush();

        expect(sentEvents()[0]['identifiable'])->toBe(['handle' => 'team_8', 'label' => 'Acme BV'])
            ->and(sentEvents()[1]['identifiable'])->toBe('team_9');
    });

    it('mixes models and strings in a list', function () {
        Bump::track('orders', identifiable: [new User(['id' => 1]), new Team(['id' => 8, 'name' => 'Acme BV']), 'store_2']);
        Bump::flush();

        expect(sentEvents()[0]['identifiable'])->toBe([
            'user_1',
            ['handle' => 'team_8', 'label' => 'Acme BV'],
            'store_2',
        ]);
    });

    it('drops the event with a warning for an unsaved model', function () {
        Log::spy();

        Bump::track('orders', identifiable: new User);
        Bump::flush();

        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'dropped an event'));
    });
});
