<?php

// Queue and sync delivery, which answers are retried, what gets logged, what reaches the queue and the
// log, and that nothing ever throws into the app.

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Sleep;
use StickySoftware\Bump\Api;
use StickySoftware\Bump\Delivery;
use StickySoftware\Bump\EventRules;
use StickySoftware\Bump\Facades\Bump;
use StickySoftware\Bump\Jobs\SendEvents;
use StickySoftware\Bump\Tests\Fixtures\Shop;
use StickySoftware\Bump\Tests\Fixtures\Team;

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

    it('encrypts the queued payload', function () {
        $this->loadLaravelMigrations();
        config(['queue.default' => 'database']);

        Bump::track('orders', note: 'first order');
        Bump::flush();

        $command = json_decode(DB::table('jobs')->value('payload'), true)['data']['command'];

        expect($command)->not->toContain('first order')
            ->and(unserialize(Crypt::decrypt($command)))->toBeInstanceOf(SendEvents::class);
    });

    it('still runs a job queued as a plain payload before the upgrade', function () {
        $this->loadLaravelMigrations();
        config(['queue.default' => 'database']);
        fakeApi();

        Bump::track('orders');
        Bump::flush();

        $payload = json_decode(DB::table('jobs')->value('payload'), true);
        $payload['data']['command'] = Crypt::decrypt($payload['data']['command']);
        DB::table('jobs')->update(['payload' => json_encode($payload)]);

        $this->artisan('queue:work', ['--once' => true]);

        expect(sentEvents())->toHaveCount(1);
    });

    it('keeps the rest of the batch when one event has text that is not valid UTF-8', function () {
        $this->loadLaravelMigrations();
        config(['queue.default' => 'database']);
        fakeApi();
        Log::spy();

        Bump::track('orders', id: 'a');
        Bump::track('orders', note: "caf\xE9");
        Bump::track('orders', id: 'b');
        Bump::flush();

        $this->artisan('queue:work', ['--once' => true]);

        expect(array_column(sentEvents(), 'id'))->toBe(['a', 'b']);
        Log::shouldHaveReceived('warning')->once()->with("Bump dropped an event with text that isn't valid UTF-8.", ['trackable' => 'orders']);
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

        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message) => $message === 'Bump could not queue 2 events. They are lost.');
    });

    it('releases a retryable batch with the Retry-After delay', function () {
        Http::fake(['*' => Http::response(['error' => ['code' => 'rate_limited']], 429, ['Retry-After' => '17'])]);

        $job = (new SendEvents([['id' => 'a', 'trackable' => 'orders']]))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        $job->assertReleased(17);
    });

    it('caps Retry-After at 15 minutes', function () {
        Http::fake(['*' => Http::response(null, 429, ['Retry-After' => '99999999999999999999999'])]);

        $job = (new SendEvents([['id' => 'a', 'trackable' => 'orders']]))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        $job->assertReleased(900);
    });

    it('reads Retry-After as an HTTP date', function () {
        $this->freezeTime();
        Http::fake(['*' => Http::response(null, 503, ['Retry-After' => now()->addSeconds(40)->utc()->format('D, d M Y H:i:s \G\M\T')])]);

        $job = (new SendEvents([['id' => 'a', 'trackable' => 'orders']]))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        $job->assertReleased(40);
    });

    it('falls back to its own backoff for a Retry-After it cannot read', function (string $header) {
        Http::fake(['*' => Http::response(null, 503, ['Retry-After' => $header])]);

        $job = (new SendEvents([['id' => 'a', 'trackable' => 'orders']]))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        $job->assertReleased(SendEvents::BACKOFF[0]);
    })->with(['-5', '1.5', 'tomorrow', '+1 year']);

    it('does not follow or retry a redirect', function () {
        Http::fake(['*' => Http::response(null, 302, ['Location' => 'https://elsewhere.example'])]);
        Log::spy();

        $job = (new SendEvents([['id' => 'a', 'trackable' => 'orders']]))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        Http::assertSentCount(1);
        $job->assertNotReleased();
        $job->assertNotFailed();
        Log::shouldHaveReceived('error')->once()->with("Bump dropped 1 event: BUMP_URL answered with a redirect (HTTP 302), which the package doesn't follow.");
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
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message) => $message === 'Bump gave up on 1 event after 6 attempts.');
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

    it('logs an answer that took at least half the sync timeout, with the time it took', function () {
        Log::spy();
        Http::fake(function () {
            $this->travel(3)->seconds();

            return Http::response(['object' => 'list', 'data' => [['object' => 'event', 'status' => 'accepted']]]);
        });

        Bump::track('orders');
        Bump::flush();

        Log::shouldHaveReceived('warning')->once()->with('Bump took 3.0s to answer (sync timeout 5s).');
    });

    it('stays quiet when the answer is quick', function () {
        Log::spy();
        Http::fake(['*' => Http::response(['object' => 'list', 'data' => [['object' => 'event', 'status' => 'accepted']]])]);

        Bump::track('orders');
        Bump::flush();

        Log::shouldNotHaveReceived('warning');
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
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $message === 'Bump gave up on 1 event after 1 attempt.'
            && $context['retry_after'] === 45);
    });

    it('caps a huge Retry-After at 15 minutes', function () {
        Http::fake(['*' => Http::response(null, 429, ['Retry-After' => (string) PHP_INT_MAX])]);
        Log::spy();

        Bump::track('orders');
        Bump::flush();

        Sleep::assertNeverSlept();
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $context['retry_after'] === 900);
    });

    it('asks the HTTP client not to follow redirects, and logs one without retrying', function () {
        $options = null;
        Http::fake(function ($request, array $requestOptions) use (&$options) {
            $options = $requestOptions;

            return Http::response(null, 301, ['Location' => 'https://elsewhere.example']);
        });
        Log::spy();

        Bump::track('orders');
        Bump::flush();

        expect($options['allow_redirects'])->toBeFalse();
        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
        Log::shouldHaveReceived('error')->once()->with("Bump dropped 1 event: BUMP_URL answered with a redirect (HTTP 301), which the package doesn't follow.");
    });

    it('drops only the event with text that is not valid UTF-8, never logging the bad value', function (array $arguments) {
        fakeApi();
        Log::spy();

        Bump::track('orders', id: 'a');
        Bump::track(...['trackable' => 'orders', ...$arguments]);
        Bump::track('orders', identifiable: new Shop(['id' => 1, 'slug' => "caf\xE9"]));
        Bump::track('orders', identifiable: new Team(['id' => 8, 'prefixed_id' => 'tem_1', 'name' => "Caf\xE9"]));
        Bump::track('orders', id: 'b');
        Bump::flush();

        expect(array_column(sentEvents(), 'id'))->toBe(['a', 'b']);
        Log::shouldHaveReceived('warning')->times(3)->withArgs(fn (string $message, array $context) => $message === "Bump dropped an event with text that isn't valid UTF-8."
            && EventRules::validUtf8($context));
    })->with([
        'trackable' => [['trackable' => "orders\xC3"]],
        'id' => [['id' => "order-\xFF"]],
        'note' => [['note' => "caf\xE9"]],
        'amount' => [['amount' => "1\xA0"]],
        'handle' => [['identifiable' => "usr_\xB1"]],
        'handle in a list' => [['identifiable' => ['usr_1', "usr_\xB1"]]],
        'handle in a map' => [['identifiable' => ['handle' => "usr_\xB1", 'label' => 'Acme']]],
        'label in a map' => [['identifiable' => ['handle' => 'usr_1', 'label' => "Acme \xB1"]]],
    ]);

    it('gives up with an error after three attempts', function () {
        Http::fake(['*' => Http::response(null, 500)]);
        Log::spy();

        Bump::track('orders');
        Bump::flush();

        Http::assertSentCount(3);
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message) => $message === 'Bump gave up on 1 event after 3 attempts.');
    });

    it('never retries 400, 401 or 403 and logs the API error', function (int $status, string $code) {
        Http::fake(['*' => apiError($status, $code, 'Nope.', 'events')]);
        Log::spy();

        Bump::track('orders');
        Bump::flush();

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
        Log::shouldHaveReceived('error')->once()->with('Bump refused 1 event.', [
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
            ['object' => 'event', 'status' => 'rejected', 'error' => ['type' => 'invalid_request_error', 'code' => 'parameter_invalid', 'message' => 'amount takes a string with two decimals, like "49.99".', 'param' => 'events[1].amount']],
        ]])]);
        Log::spy();

        Bump::track('orders', count: [1, 1]);
        Bump::track('refunds', amount: 2500);
        Bump::flush();

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $message === 'Bump rejected an event.'
            && $context['trackable'] === 'refunds'
            && $context['param'] === 'events[1].amount'
            && $context['message'] === 'amount takes a string with two decimals, like "49.99".');
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

    it('keeps the token out of the arguments in a stack trace', function () {
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');
        $exception = null;
        Http::fake(function () use (&$exception) {
            throw $exception = new RuntimeException('Something unexpected.');
        });

        try {
            app(Delivery::class)->sendNow([['id' => 'a', 'trackable' => 'orders']]);
        } catch (RuntimeException) {
        } finally {
            ini_set('zend.exception_ignore_args', (string) $ignoreArgs);
        }

        $post = collect($exception->getTrace())->first(fn (array $frame) => ($frame['class'] ?? null) === Api::class && $frame['function'] === 'post');
        $scalars = collect($exception->getTrace())->flatMap(fn (array $frame) => $frame['args'] ?? [])->filter(fn (mixed $arg) => is_scalar($arg));

        expect($post['args'][0])->toBeInstanceOf(SensitiveParameterValue::class)
            ->and($scalars)->not->toContain('bump_test_secret');
    });

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
        Log::shouldHaveReceived('error')->once()->with('Bump dropped 1 event: BUMP_TEST_TOKEN is not set.');
    });
});
