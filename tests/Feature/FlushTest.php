<?php

// When the buffer goes out: once per request, command or job, early at 100 events, never when empty.

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use StickySoftware\Bump\Facades\Bump;
use StickySoftware\Bump\Jobs\SendEvents;
use StickySoftware\Bump\Tests\Fixtures\TrackingJob;

beforeEach(function () {
    config(['bump.delivery' => 'sync']);
    fakeApi();
});

it('holds events until the buffer is flushed', function () {
    Bump::track('orders');
    Bump::track('members');

    Http::assertNothingSent();

    Bump::flush();

    Http::assertSentCount(1);
    expect(sentEvents())->toHaveCount(2);
});

it('flushes when a web request terminates', function () {
    Route::get('/checkout', function () {
        Bump::track('orders');
        Bump::track('orders');

        return 'ok';
    });

    $this->get('/checkout')->assertOk();

    Http::assertSentCount(1);
    expect(sentEvents())->toHaveCount(2);
});

it('flushes when a console command terminates', function () {
    Artisan::command('orders:import', fn () => Bump::track('orders'));

    $this->artisan('orders:import')->assertSuccessful();

    Http::assertNothingSent();

    app()->terminate();

    expect(sentEvents())->toHaveCount(1);
});

it('flushes when a queued job is processed', function () {
    dispatch(new TrackingJob);

    expect(sentEvents())->toHaveCount(1);
});

it('flushes when a queued job fails', function () {
    expect(fn () => dispatch(new TrackingJob(throws: true)))->toThrow(RuntimeException::class);

    expect(sentEvents())->toHaveCount(1);
});

describe('on a queue worker', function () {
    beforeEach(function () {
        $this->loadLaravelMigrations();
        config(['queue.default' => 'database']);
    });

    it('flushes after each job', function () {
        dispatch(new TrackingJob);
        dispatch(new TrackingJob);

        $this->artisan('queue:work', ['--once' => true]);
        expect(sentEvents())->toHaveCount(1);

        $this->artisan('queue:work', ['--once' => true]);
        expect(sentEvents())->toHaveCount(2);
    });

    it('drops, with a warning, the events of an attempt that goes back on the queue', function () {
        Log::spy();
        dispatch(new TrackingJob(throws: true));

        $this->artisan('queue:work', ['--once' => true]);
        app()->terminate();

        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->once()->with('Bump dropped 1 event from a job attempt that failed and will run again.');
    });

    it('flushes the last attempt of a failing job', function () {
        dispatch(new TrackingJob(throws: true));

        $this->artisan('queue:work', ['--once' => true]);
        $this->artisan('queue:work', ['--once' => true]);

        expect(sentEvents())->toHaveCount(1);
    });
});

it('flushes early once the buffer holds 100 events', function () {
    foreach (range(1, 100) as $ignored) {
        Bump::track('orders');
    }

    Http::assertSentCount(1);
    expect(sentEvents())->toHaveCount(100);

    Bump::track('orders');

    Http::assertSentCount(1);
});

it('counts list values toward the 100, like the API', function () {
    foreach (range(1, 99) as $ignored) {
        Bump::track('orders');
    }

    Bump::track('orders', count: [1, 1]);

    Http::assertSentCount(1);
    expect(sentEvents())->toHaveCount(99);

    Bump::flush();

    expect(sentEvents())->toHaveCount(100)
        ->and(sentEvents()[99]['count'])->toBe([1, 1]);
});

it('drops an event that alone expands past 100', function () {
    Log::spy();

    Bump::track('orders', amount: array_fill(0, 101, 1));
    Bump::flush();

    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')->once();
});

it('sends nothing when nothing was recorded', function (string $delivery) {
    config(['bump.delivery' => $delivery]);
    Queue::fake();

    $this->get('/missing');
    app()->terminate();
    event(new JobProcessed('sync', Mockery::mock(Job::class)));

    Http::assertNothingSent();
    Queue::assertNotPushed(SendEvents::class);
})->with(['sync', 'queue']);

/**
 * Octane serves each request from a clone of the booted app and resets scoped instances first.
 */
function octaneRequest(callable $handle, bool $terminate = true): void
{
    $app = app();
    $sandbox = clone $app;

    Container::setInstance($sandbox);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($sandbox);

    try {
        $sandbox->forgetScopedInstances();
        $handle();

        if ($terminate) {
            $sandbox->terminate();
        }
    } finally {
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
    }
}

describe('under Octane', function () {
    it('sends once per request, each with its own events', function () {
        inWebRequest();

        octaneRequest(fn () => Bump::track('orders'));
        octaneRequest(function () {
            Bump::track('members');
            Bump::track('members');
        });

        $requests = collect(Http::recorded())->map(fn (array $pair) => array_column($pair[0]->data()['events'], 'trackable'))->all();

        expect($requests)->toBe([['orders'], ['members', 'members']]);
    });

    it('starts every request with an empty buffer', function () {
        inWebRequest();

        Bump::track('booting');
        octaneRequest(fn () => Bump::track('orders'), terminate: false);
        octaneRequest(fn () => Bump::track('members'));

        expect(array_column(sentEvents(), 'trackable'))->toBe(['members']);
    });
});
