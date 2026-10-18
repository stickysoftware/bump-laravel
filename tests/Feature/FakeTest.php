<?php

// Bump::fake() records calls the way real delivery would build them, sends nothing, and fails assertions with readable messages.

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\ExpectationFailedException;
use StickySoftware\Bump\Facades\Bump;
use StickySoftware\Bump\Testing\BumpFake;
use StickySoftware\Bump\Testing\TrackedEvent;
use StickySoftware\Bump\Tests\Fixtures\Shop;
use StickySoftware\Bump\Tests\Fixtures\Team;
use StickySoftware\Bump\Tests\Fixtures\User;
use StickySoftware\Bump\Tracker;

afterEach(function () {
    Relation::morphMap([], merge: false);
});

describe('recording', function () {
    it('swaps the facade for a fake', function () {
        $fake = Bump::fake();

        expect($fake)->toBeInstanceOf(BumpFake::class)
            ->and(Bump::getFacadeRoot())->toBe($fake)
            ->and(app(Tracker::class))->toBe($fake)
            ->and(Bump::isFake())->toBeTrue();
    });

    it('sends and queues nothing, even when the request, command or job ends', function (string $delivery) {
        config(['bump.delivery' => $delivery]);
        Queue::fake();
        Bump::fake();

        Bump::track('orders');
        Bump::flush();
        app()->terminate();

        Http::assertNothingSent();
        Queue::assertNothingPushed();
        Bump::assertTrackedTimes('orders', 1);
    })->with(['sync', 'queue']);

    it('records without a token and when turned off', function () {
        config(['bump.test_token' => null, 'bump.enabled' => false]);
        Bump::fake();

        Bump::track('orders');

        Bump::assertTracked('orders');
    });

    it('starts empty on every fake()', function () {
        Bump::fake();
        Bump::track('orders');

        Bump::fake();

        Bump::assertNothingTracked();
    });

    it('records every argument the call passes', function () {
        Bump::fake();

        Bump::track(
            'orders',
            identifiable: 'usr_1',
            count: [1, 2],
            amount: 49.99,
            occurredAt: Carbon::parse('2026-10-02T14:03:11+02:00'),
            note: 'first order',
            activity: true,
        );

        $event = Bump::tracked('orders')->sole();

        expect($event)->toBeInstanceOf(TrackedEvent::class)
            ->and($event->trackable)->toBe('orders')
            ->and($event->identifiables)->toBe(['usr_1'])
            ->and($event->count)->toBe([1, 2])
            ->and($event->amount)->toBe(49.99)
            ->and($event->occurredAt->toAtomString())->toBe('2026-10-02T14:03:11+02:00')
            ->and($event->note)->toBe('first order')
            ->and($event->activity)->toBeTrue()
            ->and($event->adjustment)->toBeFalse();
    });

    it('leaves out what the call leaves out, and takes the time when the call happens', function () {
        Carbon::setTestNow('2026-10-15 12:00:00');
        Bump::fake();

        Bump::track('orders');
        Carbon::setTestNow('2026-10-15 12:05:00');

        $event = Bump::tracked('orders')->sole();

        expect($event->identifiables)->toBe([])
            ->and($event->count)->toBeNull()
            ->and($event->amount)->toBeNull()
            ->and($event->note)->toBeNull()
            ->and($event->activity)->toBeNull()
            ->and($event->occurredAt->toAtomString())->toBe('2026-10-15T12:00:00+00:00');
    });

    it('records adjust() with its defaults applied', function () {
        Bump::fake();

        Bump::adjust('orders', identifiable: 'usr_1', amount: -25.00, note: 'refund');

        $event = Bump::tracked('orders')->sole();

        expect($event->adjustment)->toBeTrue()
            ->and($event->count)->toBe(0)
            ->and($event->activity)->toBeFalse()
            ->and($event->amount)->toBe(-25.0);
    });

    it('lets adjust() override its defaults', function () {
        Bump::fake();

        Bump::adjust('members', count: -1, activity: true);

        $event = Bump::tracked('members')->sole();

        expect($event->adjustment)->toBeTrue()
            ->and($event->count)->toBe(-1)
            ->and($event->activity)->toBeTrue();
    });

    it('records the id the call gives, and null when the package makes one up', function () {
        Bump::fake();

        Bump::track('orders', id: 'order-1042');
        Bump::adjust('orders', amount: -5, id: 'refund-77');
        Bump::track('orders');

        expect(Bump::tracked('orders')->pluck('id')->all())->toBe(['order-1042', 'refund-77', null]);
    });

    it('drops a call with an id real delivery would drop, and says why', function (string $id, string $reason) {
        Log::spy();
        Bump::fake();

        Bump::track('orders', id: $id);

        expect(fn () => Bump::assertTracked('orders'))->toThrow(
            ExpectationFailedException::class,
            "Expected [orders] to be tracked, but it was not.\n\nBump dropped 1 call to [orders]:\n- {$reason}",
        );
        Log::shouldHaveReceived('warning')->once()->with('Bump dropped an event it could not record.', ['trackable' => 'orders', 'exception' => $reason]);
    })->with([
        'empty' => ['', 'The event id is empty.'],
        'too long' => [str_repeat('a', 65), 'The event id is 65 characters, Bump takes at most 64.'],
    ]);

    it('drops a call whose count and amount are both lists', function () {
        Log::spy();
        Bump::fake();

        Bump::track('orders', count: [1, 2], amount: [10, 20]);

        expect(fn () => Bump::assertTracked('orders'))->toThrow(
            ExpectationFailedException::class,
            "Bump dropped 1 call to [orders]:\n- count and amount can't both be lists.",
        );
    });

    it('drops a call with more than 100 values, like real delivery', function () {
        Log::spy();
        Bump::fake();

        Bump::track('orders', amount: array_fill(0, 101, 1));

        Bump::assertNothingTracked();
        Log::shouldHaveReceived('warning')->once()->with('Bump dropped an event with 101 values: a request takes at most 100.', ['trackable' => 'orders']);
    });
});

describe('model handles', function () {
    it('resolves models the way real delivery does', function () {
        Bump::fake();

        Bump::track('orders', identifiable: new User(['id' => 12, 'prefixed_id' => 'usr_3f9a']));
        Bump::track('orders', identifiable: new User(['id' => 13]));
        Bump::track('orders', identifiable: new Shop(['id' => 1, 'slug' => 'acme', 'prefixed_id' => 'shp_91d0']));
        Bump::track('orders', identifiable: new Team(['id' => 8, 'name' => 'Acme BV']));

        expect(Bump::tracked('orders')->pluck('identifiables')->all())->toBe([
            ['usr_3f9a'],
            ['user_13'],
            ['shop_acme'],
            [['handle' => 'team_8', 'label' => 'Acme BV']],
        ]);
    });

    it('follows BUMP_HANDLES and the morph map', function () {
        config(['bump.handles' => 'key']);
        Relation::morphMap(['customer' => User::class]);
        Bump::fake();

        Bump::track('orders', identifiable: new User(['id' => 12, 'prefixed_id' => 'usr_3f9a']));

        expect(Bump::tracked('orders')->sole()->identifiables)->toBe(['customer_12']);
    });

    it('keeps a list of models and handles in order', function () {
        Bump::fake();

        Bump::track('orders', identifiable: [new User(['id' => 1, 'prefixed_id' => 'usr_3f9a']), new Team(['id' => 8, 'name' => 'Acme BV']), 'store_2']);

        expect(Bump::tracked('orders')->sole()->identifiables)->toBe([
            'usr_3f9a',
            ['handle' => 'team_8', 'label' => 'Acme BV'],
            'store_2',
        ]);
    });

    it('matches a model or a handle with hasIdentifiable()', function () {
        Bump::fake();
        $user = new User(['id' => 12, 'prefixed_id' => 'usr_3f9a']);
        $team = new Team(['id' => 8, 'name' => 'Acme BV']);

        Bump::track('orders', identifiable: [$user, $team]);

        $event = Bump::tracked('orders')->sole();

        expect($event->hasIdentifiable($user))->toBeTrue()
            ->and($event->hasIdentifiable($team))->toBeTrue()
            ->and($event->hasIdentifiable('team_8'))->toBeTrue()
            ->and($event->hasIdentifiable(new User(['id' => 12])))->toBeFalse()
            ->and($event->hasIdentifiable('user_12'))->toBeFalse();
    });

    it('drops a call whose handle real delivery would drop, and says why when the assertion fails', function () {
        Log::spy();
        Bump::fake();

        Bump::track('orders', identifiable: new Shop(['id' => 3, 'slug' => str_repeat('a', 60)]));

        expect(fn () => Bump::assertTracked('orders'))->toThrow(
            ExpectationFailedException::class,
            "Expected [orders] to be tracked, but it was not.\n\nBump dropped 1 call to [orders]:\n- The Bump handle for ".Shop::class.' is 65 characters, Bump takes at most 64.',
        );
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => $message === 'Bump dropped an event it could not record.');
    });

    it('drops a call for an unsaved model', function () {
        Bump::fake();

        Bump::track('orders', identifiable: new User);

        expect(fn () => Bump::assertTrackedTimes('orders', 1))->toThrow(
            ExpectationFailedException::class,
            "Expected [orders] to be tracked 1 time, but it was tracked 0 times.\n\nBump dropped 1 call to [orders]:\n- An unsaved ".User::class.' has no key to build a Bump handle from.',
        );
    });
});

describe('assertTracked', function () {
    it('passes when the trackable was tracked', function () {
        Bump::fake();

        Bump::track('orders');

        Bump::assertTracked('orders');
    });

    it('counts adjust() calls as tracked', function () {
        Bump::fake();

        Bump::adjust('orders', amount: -10);

        Bump::assertTracked('orders', fn (TrackedEvent $event) => $event->adjustment);
    });

    it('passes when a call matches the callback', function () {
        Bump::fake();

        Bump::track('orders', amount: 10);
        Bump::track('orders', amount: 49.99);

        Bump::assertTracked('orders', fn (TrackedEvent $event) => $event->amount === 49.99);
    });

    it('fails when the trackable was not tracked', function () {
        Bump::fake();

        Bump::track('members');

        expect(fn () => Bump::assertTracked('orders'))
            ->toThrow(ExpectationFailedException::class, 'Expected [orders] to be tracked, but it was not.');
    });

    it('fails when no call matches the callback', function () {
        Bump::fake();

        Bump::track('orders', amount: 10);
        Bump::track('orders', amount: 20);

        expect(fn () => Bump::assertTracked('orders', fn (TrackedEvent $event) => $event->amount === 49.99))
            ->toThrow(ExpectationFailedException::class, 'Expected [orders] to be tracked matching the callback, but it was tracked 2 times and none matched.');
    });

    it('fails with a callback when the trackable was not tracked at all', function () {
        Bump::fake();

        expect(fn () => Bump::assertTracked('orders', fn () => true))
            ->toThrow(ExpectationFailedException::class, 'Expected [orders] to be tracked matching the callback, but it was not tracked.');
    });
});

describe('assertTrackedTimes', function () {
    it('passes on the exact number of calls', function () {
        Bump::fake();

        Bump::track('orders', count: [1, 1]);
        Bump::adjust('orders', amount: -5);

        Bump::assertTrackedTimes('orders', 2);
    });

    it('defaults to once', function () {
        Bump::fake();

        Bump::track('orders');

        Bump::assertTrackedTimes('orders');
    });

    it('fails on a different number of calls', function () {
        Bump::fake();

        Bump::track('orders');

        expect(fn () => Bump::assertTrackedTimes('orders', 2))
            ->toThrow(ExpectationFailedException::class, 'Expected [orders] to be tracked 2 times, but it was tracked 1 time.');
    });
});

describe('assertNotTracked', function () {
    it('passes when the trackable was not tracked', function () {
        Bump::fake();

        Bump::track('members');

        Bump::assertNotTracked('orders');
    });

    it('passes when no call matches the callback', function () {
        Bump::fake();

        Bump::track('orders', amount: 10);

        Bump::assertNotTracked('orders', fn (TrackedEvent $event) => $event->adjustment);
    });

    it('fails when the trackable was tracked', function () {
        Bump::fake();

        Bump::track('orders');
        Bump::track('orders');

        expect(fn () => Bump::assertNotTracked('orders'))
            ->toThrow(ExpectationFailedException::class, 'Expected [orders] not to be tracked, but it was tracked 2 times.');
    });

    it('fails when a call matches the callback', function () {
        Bump::fake();

        Bump::track('orders');
        Bump::adjust('orders', amount: -10);

        expect(fn () => Bump::assertNotTracked('orders', fn (TrackedEvent $event) => $event->adjustment))
            ->toThrow(ExpectationFailedException::class, 'Expected [orders] not to be tracked matching the callback, but it matched 1 time.');
    });
});

describe('assertNothingTracked', function () {
    it('passes when nothing was tracked', function () {
        Bump::fake();

        Bump::assertNothingTracked();
    });

    it('fails with what was tracked', function () {
        Bump::fake();

        Bump::track('orders');
        Bump::track('members');
        Bump::adjust('orders', amount: -10);

        expect(fn () => Bump::assertNothingTracked())
            ->toThrow(ExpectationFailedException::class, "Expected nothing to be tracked, but these were:\n\n- [orders] 2 times\n- [members] 1 time");
    });
});

describe('forget', function () {
    it('records forgotten handles, resolving models the way real delivery does', function () {
        Bump::fake();
        $user = new User(['id' => 12, 'prefixed_id' => 'usr_3f9a']);

        Bump::forget($user);
        Bump::forget('team_8');
        Bump::forget(42);

        Bump::assertForgotten($user);
        Bump::assertForgotten('usr_3f9a');
        Bump::assertForgotten('team_8');
        Bump::assertForgotten(42);
        Bump::assertNotForgotten('usr_other');
        expect(Bump::forgotten()->all())->toBe(['usr_3f9a', 'team_8', '42']);
    });

    it('records without a token and when turned off', function () {
        config(['bump.test_token' => null, 'bump.enabled' => false]);
        Bump::fake();

        Bump::forget('usr_1');

        Bump::assertForgotten('usr_1');
    });

    it('fails assertForgotten with the handle', function () {
        Bump::fake();

        expect(fn () => Bump::assertForgotten(new User(['id' => 12, 'prefixed_id' => 'usr_3f9a'])))
            ->toThrow(ExpectationFailedException::class, 'Expected [usr_3f9a] to be forgotten, but it was not.');
    });

    it('fails assertNotForgotten with the handle', function () {
        Bump::fake();

        Bump::forget('usr_1');

        expect(fn () => Bump::assertNotForgotten('usr_1'))
            ->toThrow(ExpectationFailedException::class, 'Expected [usr_1] not to be forgotten, but it was.');
    });

    it('drops a forget real delivery would drop, and says why when the assertion fails', function () {
        Log::spy();
        Bump::fake();

        Bump::forget(new User);

        expect(fn () => Bump::assertForgotten('user_1'))->toThrow(
            ExpectationFailedException::class,
            "Expected [user_1] to be forgotten, but it was not.\n\nBump dropped 1 call to forget():\n- An unsaved ".User::class.' has no key to build a Bump handle from.',
        );
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => $message === 'Bump could not forget an identifiable.');
    });

    it('sends and queues nothing', function () {
        Queue::fake();
        Bump::fake();

        Bump::track('orders');
        Bump::forget('usr_1');

        Http::assertNothingSent();
        Queue::assertNothingPushed();
    });
});

describe('tracked', function () {
    it('returns the calls to a trackable in order, filtered by the callback', function () {
        Bump::fake();

        Bump::track('orders', amount: 10);
        Bump::track('members');
        Bump::track('orders', amount: 20);
        Bump::adjust('orders', amount: -5);

        expect(Bump::tracked('orders')->pluck('amount')->all())->toBe([10, 20, -5])
            ->and(Bump::tracked('orders', fn (TrackedEvent $event) => $event->amount > 0)->pluck('amount')->all())->toBe([10, 20])
            ->and(Bump::tracked('refunds'))->toBeEmpty();
    });
});
