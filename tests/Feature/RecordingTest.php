<?php

// What a call puts in the request body, which token sends it, and when nothing is sent at all.

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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

    it('names a model after its prefixed ID by default', function () {
        Bump::track('orders', identifiable: new User(['id' => 12, 'prefixed_id' => 'usr_3f9a']));
        Bump::flush();

        expect(sentEvents()[0]['identifiable'])->toBe('usr_3f9a');
    });

    it('names a model after its class and key without a prefixed ID', function () {
        Bump::track('orders', identifiable: new User(['id' => 12]));
        Bump::track('orders', identifiable: new User(['id' => 13, 'prefixed_id' => null]));
        Bump::track('orders', identifiable: new User(['id' => 14, 'prefixed_id' => '']));
        Bump::track('orders', identifiable: new TeamMember(['id' => 3]));
        Bump::flush();

        expect(array_column(sentEvents(), 'identifiable'))->toBe(['user_12', 'user_13', 'user_14', 'team_member_3']);
    });

    it('ignores the prefixed ID with handles set to key', function () {
        config(['bump.handles' => 'key']);

        Bump::track('orders', identifiable: new User(['id' => 12, 'prefixed_id' => 'usr_3f9a']));
        Bump::flush();

        expect(sentEvents()[0]['identifiable'])->toBe('user_12');
    });

    it('reads the attribute name from the prefixed-ids config', function () {
        config(['prefixed-ids.prefixed_id_attribute_name' => 'public_id']);

        Bump::track('orders', identifiable: new User(['id' => 12, 'public_id' => 'usr_77c1', 'prefixed_id' => 'usr_old']));
        Bump::flush();

        expect(sentEvents()[0]['identifiable'])->toBe('usr_77c1');
    });

    it('treats an unknown handles setting as key, warning once', function () {
        config(['bump.handles' => 'uuid']);
        Log::spy();

        Bump::track('orders', identifiable: new User(['id' => 12, 'prefixed_id' => 'usr_3f9a']));
        Bump::track('orders', identifiable: new User(['id' => 13, 'prefixed_id' => 'usr_8b2e']));
        Bump::flush();

        expect(array_column(sentEvents(), 'identifiable'))->toBe(['user_12', 'user_13']);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'BUMP_HANDLES=uuid'));
    });

    it('prefers the morph map alias', function () {
        Relation::morphMap(['customer' => User::class]);

        Bump::track('orders', identifiable: new User(['id' => 12]));
        Bump::flush();

        expect(sentEvents()[0]['identifiable'])->toBe('customer_12');
    });

    it('uses bumpHandle() when the model has one', function (string $handles) {
        config(['bump.handles' => $handles]);

        Bump::track('orders', identifiable: new Shop(['id' => 1, 'slug' => 'acme', 'prefixed_id' => 'shp_91d0']));
        Bump::flush();

        expect(sentEvents()[0]['identifiable'])->toBe('shop_acme');
    })->with(['prefixed_id', 'key']);

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

    it('mixes models with prefixed IDs in a list', function () {
        $user = new User(['id' => 1, 'prefixed_id' => 'usr_3f9a']);
        $team = new Team(['id' => 8, 'prefixed_id' => 'tem_c02d', 'name' => 'Acme BV']);

        Bump::track('orders', identifiable: [$user, $team]);
        Bump::flush();

        expect(sentEvents()[0]['identifiable'])->toBe([
            'usr_3f9a',
            ['handle' => 'tem_c02d', 'label' => 'Acme BV'],
        ]);
    });

    it('drops the event with a warning for a handle over 64 characters', function () {
        Log::spy();

        Bump::track('orders', identifiable: new User(['id' => 1, 'prefixed_id' => 'usr_'.str_repeat('a', 60)]));
        Bump::track('orders', identifiable: new User(['id' => 2, 'prefixed_id' => 'usr_'.str_repeat('a', 61)]));
        Bump::track('orders', identifiable: new Shop(['id' => 3, 'slug' => str_repeat('a', 60)]));
        Bump::flush();

        expect(sentEvents())->toHaveCount(1)
            ->and(sentEvents()[0]['identifiable'])->toBe('usr_'.str_repeat('a', 60));
        Log::shouldHaveReceived('warning')->twice()->withArgs(fn (string $message, array $context) => str_contains($message, 'dropped an event')
            && str_contains($context['exception'], 'at most 64'));
    });

    it('warns once per class when a model with the trait has no prefixed ID', function () {
        Log::spy();

        Bump::track('orders', identifiable: new User(['id' => 12, 'prefixed_id' => null]));
        Bump::track('orders', identifiable: new User(['id' => 13, 'prefixed_id' => '']));
        Bump::track('orders', identifiable: new Team(['id' => 8, 'prefixed_id' => null]));
        Bump::track('orders', identifiable: new TeamMember(['id' => 3]));
        Bump::flush();

        expect(array_column(sentEvents(), 'identifiable'))->toBe(['user_12', 'user_13', 'team_8', 'team_member_3']);
        Log::shouldHaveReceived('warning', ['Bump fell back to user_12 style handles for '.User::class.': some rows have no prefixed_id.'])->once();
        Log::shouldHaveReceived('warning', ['Bump fell back to team_8 style handles for '.Team::class.': some rows have no prefixed_id.'])->once();
        Log::shouldHaveReceived('warning')->twice();
    });

    describe('stored models', function () {
        beforeEach(function () {
            config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);

            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('prefixed_id')->nullable();
                $table->softDeletes();
            });

            DB::table('users')->insert([
                ['id' => 1, 'prefixed_id' => 'usr_3f9a', 'deleted_at' => null],
                ['id' => 2, 'prefixed_id' => 'usr_8b2e', 'deleted_at' => '2026-10-01 12:00:00'],
                ['id' => 3, 'prefixed_id' => null, 'deleted_at' => null],
            ]);
        });

        it('fetches the prefixed ID of a partly loaded model once', function () {
            $user = User::query()->select('id')->find(1);
            $queries = 0;
            DB::listen(function () use (&$queries) {
                $queries++;
            });

            Bump::track('orders', identifiable: $user);
            Bump::track('orders', identifiable: $user);
            Bump::flush();

            expect(array_column(sentEvents(), 'identifiable'))->toBe(['usr_3f9a', 'usr_3f9a'])
                ->and($queries)->toBe(1)
                ->and($user->isDirty())->toBeFalse();
        });

        it('fetches the prefixed ID of a soft-deleted model', function () {
            Bump::track('orders', identifiable: User::withTrashed()->select('id')->find(2));
            Bump::flush();

            expect(sentEvents()[0]['identifiable'])->toBe('usr_8b2e');
        });

        it('falls back with a warning when the fetched prefixed ID is empty', function () {
            Log::spy();
            $user = User::query()->select('id')->find(3);

            Bump::track('orders', identifiable: $user);
            Bump::track('orders', identifiable: $user);
            Bump::flush();

            expect(array_column(sentEvents(), 'identifiable'))->toBe(['user_3', 'user_3'])
                ->and($user->isDirty())->toBeFalse();
            Log::shouldHaveReceived('warning', ['Bump fell back to user_3 style handles for '.User::class.': some rows have no prefixed_id.'])->once();
        });
    });

    it('drops the event with a warning for an unsaved model', function () {
        Log::spy();

        Bump::track('orders', identifiable: new User);
        Bump::flush();

        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'dropped an event'));
    });
});
