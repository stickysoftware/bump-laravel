<?php

// Queue and sync delivery, which answers are retried, what gets logged, and that nothing ever throws into the app.

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Sleep;
use StickySoftware\Bump\Delivery;
use StickySoftware\Bump\Facades\Bump;
use StickySoftware\Bump\Jobs\SendEvents;

beforeEach(function () {
    Sleep::fake();
});

function apiError(int $status, string $code, string $message, ?string $param = null): mixed
{
    return Http::response(['error' => ['type' => 'invalid_request_error', 'code' => $code, 'message' => $message, 'param' => $param]], $status);
}

describe('queue delivery', function () {
    it('queues one job with the events', function () {
        Queue::fake();

        Bump::track('orders');
        Bump::track('members');
        Bump::flush();

        Queue::assertPushed(SendEvents::class, fn (SendEvents $job) => count($job->events) === 2
            && $job->events[1]['trackable'] === 'members'
            && $job->connection === null
            && $job->queue === null);
    });

    it('uses the configured connection and queue', function () {
        config(['bump.queue.connection' => 'redis', 'bump.queue.name' => 'bump']);
        Queue::fake();

        Bump::track('orders');
        Bump::flush();

        Queue::assertPushed(SendEvents::class, fn (SendEvents $job) => $job->connection === 'redis' && $job->queue === 'bump');
    });

    it('keeps the token out of the queued payload', function () {
        $this->loadLaravelMigrations();
        config(['queue.default' => 'database']);

        Bump::track('orders');
        Bump::flush();

        expect(DB::table('jobs')->value('payload'))->not->toContain('bump_test_secret');
    });

    it('sends the events when a worker runs the job', function () {
        $this->loadLaravelMigrations();
        config(['queue.default' => 'database']);
        fakeApi();

        Bump::track('orders');
        Bump::flush();
        Http::assertNothingSent();

        $this->artisan('queue:work', ['--once' => true]);

        expect(sentEvents())->toHaveCount(1);
    });

    it('logs the events as lost when they cannot be queued', function () {
        config(['bump.queue.connection' => 'missing']);
        Log::spy();

        Bump::track('orders');
        Bump::track('orders');
        Bump::flush();

        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message) => $message === 'Bump could not queue 2 events, they are lost.');
    });

    it('releases a retryable batch with the Retry-After delay', function () {
        Http::fake(['*' => Http::response(['error' => ['code' => 'rate_limited']], 429, ['Retry-After' => '17'])]);

        $job = (new SendEvents([['id' => 'a', 'trackable' => 'orders']]))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        $job->assertReleased(17);
    });

    it('releases with its own backoff without Retry-After', function () {
        Http::fake(['*' => Http::response(null, 503)]);

        $job = (new SendEvents([['id' => 'a', 'trackable' => 'orders']]))->withFakeQueueInteractions();
        $job->job->attempts = 2;
        app()->call([$job, 'handle']);

        $job->assertReleased(SendEvents::BACKOFF[1]);
    });

    it('releases after a connection error', function () {
        Http::fake(['*' => Http::failedConnection()]);

        $job = (new SendEvents([['id' => 'a', 'trackable' => 'orders']]))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        $job->assertReleased(SendEvents::BACKOFF[0]);
    });

    it('gives up with an error on the last attempt', function () {
        Http::fake(['*' => Http::response(null, 500)]);
        Log::spy();

        $job = (new SendEvents([['id' => 'a', 'trackable' => 'orders']]))->withFakeQueueInteractions();
        $job->job->attempts = $job->tries;
        app()->call([$job, 'handle']);

        $job->assertNotReleased();
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message) => $message === 'Bump gave up on 1 events after 6 attempts.');
    });

    it('does not retry what the API refuses', function (int $status) {
        Http::fake(['*' => apiError($status, 'token_invalid', 'No.')]);

        $job = (new SendEvents([['id' => 'a', 'trackable' => 'orders']]))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        $job->assertNotReleased();
        $job->assertNotFailed();
    })->with([400, 401, 403]);
});

describe('sync delivery', function () {
    beforeEach(function () {
        config(['bump.delivery' => 'sync']);
    });

    it('retries 5xx, 429 and connection errors in-process', function () {
        Http::fake(['*' => Http::sequence()
            ->push(null, 502)
            ->pushFailedConnection()
            ->push(['object' => 'list', 'data' => [['object' => 'event', 'status' => 'accepted']]]),
        ]);

        Bump::track('orders');
        Bump::flush();

        Http::assertSentCount(3);
        Sleep::assertSequence([Sleep::for(1)->seconds(), Sleep::for(5)->seconds()]);

        $ids = collect(Http::recorded())->map(fn (array $pair) => $pair[0]->data()['events'][0]['id'])->unique();
        expect($ids)->toHaveCount(1);
    });

    it('waits as long as Retry-After says', function () {
        Http::fake(['*' => Http::sequence()
            ->push(null, 429, ['Retry-After' => '3'])
            ->push(['object' => 'list', 'data' => [['object' => 'event', 'status' => 'accepted']]]),
        ]);

        Bump::track('orders');
        Bump::flush();

        Http::assertSentCount(2);
        Sleep::assertSequence([Sleep::for(3)->seconds()]);
    });

    it('gives up instead of holding the request for a long Retry-After', function () {
        Http::fake(['*' => Http::response(null, 429, ['Retry-After' => '45'])]);
        Log::spy();

        Bump::track('orders');
        Bump::flush();

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $message === 'Bump gave up on 1 events after 1 attempts.'
            && $context['retry_after'] === 45);
    });

    it('gives up with an error after three attempts', function () {
        Http::fake(['*' => Http::response(null, 500)]);
        Log::spy();

        Bump::track('orders');
        Bump::flush();

        Http::assertSentCount(3);
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message) => $message === 'Bump gave up on 1 events after 3 attempts.');
    });

    it('never retries 400, 401 or 403 and logs the API error', function (int $status, string $code) {
        Http::fake(['*' => apiError($status, $code, 'Nope.', 'events')]);
        Log::spy();

        Bump::track('orders');
        Bump::flush();

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
        Log::shouldHaveReceived('error')->once()->with('Bump refused 1 events.', [
            'status' => $status,
            'code' => $code,
            'message' => 'Nope.',
            'param' => 'events',
        ]);
    })->with([
        [400, 'parameter_invalid'],
        [401, 'authentication_error'],
        [403, 'missing_ability'],
    ]);

    it('logs each rejected event with its param and message, matching results after expansion', function () {
        Http::fake(['*' => Http::response(['object' => 'list', 'data' => [
            ['object' => 'event', 'id' => 'evt_1', 'status' => 'accepted'],
            ['object' => 'event', 'id' => 'evt_2', 'status' => 'duplicate'],
            ['object' => 'event', 'status' => 'rejected', 'error' => ['type' => 'invalid_request_error', 'code' => 'parameter_invalid', 'message' => 'Amounts take at most 2 decimals.', 'param' => 'events[1].amount']],
        ]])]);
        Log::spy();

        Bump::track('orders', count: [1, 1]);
        Bump::track('refunds', amount: 1.005);
        Bump::flush();

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $message === 'Bump rejected an event.'
            && $context['trackable'] === 'refunds'
            && $context['param'] === 'events[1].amount'
            && $context['message'] === 'Amounts take at most 2 decimals.');
    });

    it('retries a 200 that is not the API answering', function () {
        Http::fake(['*' => Http::sequence()
            ->push('<html>Login</html>', 200)
            ->push(['object' => 'list', 'data' => [['object' => 'event', 'status' => 'accepted']]]),
        ]);

        Bump::track('orders');
        Bump::flush();

        Http::assertSentCount(2);
    });
});

describe('never throws into the app', function () {
    it('swallows any failure while sending', function (string $delivery) {
        config(['bump.delivery' => $delivery]);
        Http::fake(fn () => throw new RuntimeException('Something unexpected.'));
        Log::spy();
        inWebRequest();
        Route::get('/checkout', function () {
            Bump::track('orders');
            Bump::flush();

            return 'ok';
        });

        $this->get('/checkout')->assertOk()->assertSee('ok');

        Log::shouldHaveReceived('error')->once();
    })->with(['sync', 'queue']);

    it('swallows a failure while recording', function () {
        Log::spy();

        Bump::track('orders', occurredAt: new class extends DateTimeImmutable
        {
            public function format(string $format): string
            {
                throw new RuntimeException('A broken date.');
            }
        });

        Log::shouldHaveReceived('warning')->once();
    });

    it('logs a missing token when a queued job runs without one', function () {
        config(['bump.test_token' => null]);
        Http::fake();
        Log::spy();

        $job = (new SendEvents([['id' => 'a', 'trackable' => 'orders']]))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        Http::assertNothingSent();
        Log::shouldHaveReceived('error')->once()->with('Bump dropped 1 events: BUMP_TEST_TOKEN is not set.');
    });
});

it('posts to the configured URL with or without a trailing slash', function () {
    config(['bump.url' => 'https://api.bump.example/v1/']);
    fakeApi();

    app(Delivery::class)->sendNow([['id' => 'a', 'trackable' => 'orders']]);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.bump.example/v1/events');
});
