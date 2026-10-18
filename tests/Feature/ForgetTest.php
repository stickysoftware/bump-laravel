<?php

// Bump::forget() sends the events recorded so far, then asks Bump to forget the identifiable, and never throws.

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use StickySoftware\Bump\Facades\Bump;
use StickySoftware\Bump\Jobs\ForgetIdentifiable;
use StickySoftware\Bump\Jobs\SendEvents;
use StickySoftware\Bump\Tests\Fixtures\User;

beforeEach(function () {
    Sleep::fake();
});

function fakeForgetApi(mixed $forgetResponse = null): void
{
    Http::fake(function (Request $request) use ($forgetResponse) {
        if (str_ends_with($request->url(), '/identifiables/forget')) {
            return $forgetResponse ?? Http::response(['object' => 'identifiable', 'handle' => $request->data()['identifiable'], 'forgotten' => true]);
        }

        return Http::response(['object' => 'list', 'data' => array_map(
            fn () => ['object' => 'event', 'status' => 'accepted'],
            $request->data()['events'],
        )]);
    });
}

/**
 * @return list<string>
 */
function requestedPaths(): array
{
    return collect(Http::recorded())->map(fn (array $pair) => parse_url($pair[0]->url(), PHP_URL_PATH))->all();
}

describe('sync delivery', function () {
    beforeEach(function () {
        config(['bump.delivery' => 'sync']);
    });

    it('posts the handle in the body, never the URL', function () {
        fakeForgetApi();

        Bump::forget('usr_3f9a');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.bump.example/v1/identifiables/forget'
            && $request->method() === 'POST'
            && $request->data() === ['identifiable' => 'usr_3f9a']
            && $request->hasHeader('Authorization', 'Bearer bump_test_secret'));
        Http::assertSentCount(1);
    });

    it('resolves a model to its handle and takes an integer handle as text', function () {
        fakeForgetApi();

        Bump::forget(new User(['id' => 12, 'prefixed_id' => 'usr_3f9a']));
        Bump::forget(42);

        expect(collect(Http::recorded())->map(fn (array $pair) => $pair[0]->data()['identifiable'])->all())->toBe(['usr_3f9a', '42']);
    });

    it('sends the events recorded so far first', function () {
        fakeForgetApi();

        Bump::track('orders', identifiable: 'usr_3f9a');
        Bump::track('members');
        Bump::forget('usr_3f9a');
        Bump::flush();

        expect(requestedPaths())->toBe(['/v1/events', '/v1/identifiables/forget']);
    });

    it('logs nothing above debug when Bump answers 200', function () {
        fakeForgetApi();
        Log::spy();

        Bump::forget('usr_3f9a');

        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    });

    it('takes a 404 for the handle as already forgotten', function () {
        fakeForgetApi(Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No identifiable with that handle in this environment.', 'param' => 'identifiable']], 404));
        Log::spy();

        Bump::forget('usr_3f9a');

        Http::assertSentCount(1);
        Log::shouldHaveReceived('debug')->once()->with('Bump had no identifiable with that handle to forget.');
        Log::shouldNotHaveReceived('error');
    });

    it('logs a 404 for an unknown URL as refused', function () {
        fakeForgetApi(Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'Unrecognized request URL.', 'param' => null]], 404));
        Log::spy();

        Bump::forget('usr_3f9a');

        Http::assertSentCount(1);
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $message === 'Bump refused to forget an identifiable.'
            && $context['status'] === 404);
    });

    it('warns once, without retrying, when the token lacks the forget ability', function () {
        fakeForgetApi(Http::response(['error' => ['type' => 'permission_error', 'code' => 'missing_ability', 'message' => "This API token doesn't have the identifiables:forget ability.", 'param' => null]], 403));
        Log::spy();

        Bump::forget('usr_3f9a');

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
        Log::shouldHaveReceived('warning')->once()->with('Bump could not forget an identifiable: BUMP_TEST_TOKEN can\'t forget identifiables. Create a token with "Can forget identifiables" turned on.');
    });

    it('retries 5xx, 429 and connection errors', function () {
        Http::fake(['*' => Http::sequence()
            ->push(null, 503)
            ->pushFailedConnection()
            ->push(['object' => 'identifiable', 'handle' => 'usr_3f9a', 'forgotten' => true]),
        ]);

        Bump::forget('usr_3f9a');

        Http::assertSentCount(3);
        Sleep::assertSequence([Sleep::for(1)->seconds(), Sleep::for(5)->seconds()]);
    });

    it('retries a 200 that is not the API answering', function () {
        Http::fake(['*' => Http::sequence()
            ->push('<html>Login</html>', 200)
            ->push(['object' => 'identifiable', 'handle' => 'usr_3f9a', 'forgotten' => true]),
        ]);

        Bump::forget('usr_3f9a');

        Http::assertSentCount(2);
    });

    it('gives up with an error after three attempts, without the handle', function () {
        Http::fake(['*' => Http::response(null, 500)]);
        Log::spy();

        Bump::forget('usr_3f9a');

        Http::assertSentCount(3);
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $message === 'Bump gave up on forgetting an identifiable after 3 attempts.'
            && ! str_contains(json_encode($context), 'usr_3f9a'));
    });

    it('sends with the live token in production', function () {
        app()['env'] = 'production';
        fakeForgetApi();

        Bump::forget('usr_3f9a');

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer bump_live_secret'));
    });
});

describe('queue delivery', function () {
    it('chains the forget after the events recorded so far', function () {
        Queue::fake();

        Bump::track('orders', identifiable: 'usr_3f9a');
        Bump::forget('usr_3f9a');
        Bump::flush();

        Queue::assertPushedWithChain(SendEvents::class, [new ForgetIdentifiable('usr_3f9a')], fn (SendEvents $job) => count($job->events) === 1);
        Queue::assertPushed(SendEvents::class, 1);
    });

    it('queues the forget alone when nothing was recorded', function () {
        Queue::fake();

        Bump::forget('usr_3f9a');

        Queue::assertPushed(ForgetIdentifiable::class, fn (ForgetIdentifiable $job) => $job->handle === 'usr_3f9a');
        Queue::assertNotPushed(SendEvents::class);
    });

    it('uses the configured connection and queue for both', function () {
        config(['bump.queue.connection' => 'redis', 'bump.queue.name' => 'bump']);
        Queue::fake();

        Bump::track('orders');
        Bump::forget('usr_1');
        Bump::forget('usr_2');

        Queue::assertPushed(SendEvents::class, fn (SendEvents $job) => $job->connection === 'redis' && $job->queue === 'bump'
            && $job->chainConnection === 'redis' && $job->chainQueue === 'bump');
        Queue::assertPushed(ForgetIdentifiable::class, fn (ForgetIdentifiable $job) => $job->connection === 'redis' && $job->queue === 'bump');
    });

    it('runs the forget on a worker after the events are sent', function () {
        $this->loadLaravelMigrations();
        config(['queue.default' => 'database']);
        fakeForgetApi();

        Bump::track('orders', identifiable: 'usr_3f9a');
        Bump::forget('usr_3f9a');

        expect(DB::table('jobs')->count())->toBe(1);

        $this->artisan('queue:work', ['--once' => true]);
        expect(requestedPaths())->toBe(['/v1/events']);

        $this->artisan('queue:work', ['--once' => true]);
        expect(requestedPaths())->toBe(['/v1/events', '/v1/identifiables/forget']);
    });

    it('keeps the token out of the queued payload', function () {
        $this->loadLaravelMigrations();
        config(['queue.default' => 'database']);

        Bump::forget('usr_3f9a');

        expect(DB::table('jobs')->value('payload'))->not->toContain('bump_test_secret');
    });

    it('releases a retryable forget with the Retry-After delay', function () {
        Http::fake(['*' => Http::response(['error' => ['code' => 'rate_limited']], 429, ['Retry-After' => '17'])]);

        $job = (new ForgetIdentifiable('usr_3f9a'))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        $job->assertReleased(17);
    });

    it('releases with its own backoff after a 5xx', function () {
        Http::fake(['*' => Http::response(null, 502)]);

        $job = (new ForgetIdentifiable('usr_3f9a'))->withFakeQueueInteractions();
        $job->job->attempts = 2;
        app()->call([$job, 'handle']);

        $job->assertReleased(SendEvents::BACKOFF[1]);
    });

    it('does not retry a token without the forget ability', function () {
        Http::fake(['*' => Http::response(['error' => ['type' => 'permission_error', 'code' => 'missing_ability']], 403)]);

        $job = (new ForgetIdentifiable('usr_3f9a'))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        $job->assertNotReleased();
        $job->assertNotFailed();
    });

    it('gives up with an error on the last attempt', function () {
        Http::fake(['*' => Http::response(null, 500)]);
        Log::spy();

        $job = (new ForgetIdentifiable('usr_3f9a'))->withFakeQueueInteractions();
        $job->job->attempts = $job->tries;
        app()->call([$job, 'handle']);

        $job->assertNotReleased();
        Log::shouldHaveReceived('error')->once()->with('Bump gave up on forgetting an identifiable after 6 attempts.', ['reason' => 'HTTP 500']);
    });

    it('still queues the forget when the send job before it fails for good', function () {
        Queue::fake();

        $send = new SendEvents([['id' => 'a', 'trackable' => 'orders']]);
        $send->chain([new ForgetIdentifiable('usr_3f9a')]);
        $send->failed(new RuntimeException('Timed out.'));

        Queue::assertPushed(ForgetIdentifiable::class, fn (ForgetIdentifiable $job) => $job->handle === 'usr_3f9a');
    });

    it('logs the events and the forget as lost when they cannot be queued', function () {
        config(['bump.queue.connection' => 'missing']);
        Log::spy();

        Bump::track('orders');
        Bump::forget('usr_3f9a');

        Log::shouldHaveReceived('error')->with('Bump could not queue 1 event. It is lost.', Mockery::any())->once();
        Log::shouldHaveReceived('error')->with('Bump could not queue a forget request. The identifiable is not forgotten.', Mockery::any())->once();
    });
});

describe('never throws into the app', function () {
    it('drops a forget for an unsaved model with a warning', function () {
        Queue::fake();
        Log::spy();

        Bump::forget(new User);

        Queue::assertNothingPushed();
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $message === 'Bump could not forget an identifiable.'
            && str_contains($context['exception'], 'unsaved'));
    });

    it('drops a forget for an empty handle with a warning', function () {
        Queue::fake();
        Log::spy();

        Bump::forget('');

        Queue::assertNothingPushed();
        Log::shouldHaveReceived('warning')->once();
    });

    it('drops a forget when turned off or without a token', function (array $config) {
        config($config);
        Queue::fake();

        Bump::forget('usr_3f9a');

        Queue::assertNothingPushed();
    })->with([
        'turned off' => [['bump.enabled' => false]],
        'no token' => [['bump.test_token' => null]],
    ]);

    it('logs a missing token when the queued forget runs without one', function () {
        config(['bump.test_token' => null]);
        Http::fake();
        Log::spy();

        $job = (new ForgetIdentifiable('usr_3f9a'))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        Http::assertNothingSent();
        Log::shouldHaveReceived('error')->once()->with('Bump could not forget an identifiable: BUMP_TEST_TOKEN is not set.');
    });

    it('swallows any failure while forgetting', function () {
        config(['bump.delivery' => 'sync']);
        Http::fake(fn () => throw new RuntimeException('Something unexpected.'));
        Log::spy();

        Bump::forget('usr_3f9a');

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => $message === 'Bump could not forget an identifiable.');
    });
});
