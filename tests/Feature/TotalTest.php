<?php

// Bump::total() reads one trackable's totals with a synchronous GET, and throws a BumpException when it can't.

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use StickySoftware\Bump\Exceptions\BumpException;
use StickySoftware\Bump\Facades\Bump;
use StickySoftware\Bump\Total;

beforeEach(function () {
    Sleep::fake();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function trackableBody(array $overrides = []): array
{
    return array_merge([
        'object' => 'trackable',
        'handle' => 'exports.png',
        'count' => 3,
        'amount' => '59.94',
        'currency' => 'EUR',
        'last_event_at' => '2026-10-18T16:48:55Z',
    ], $overrides);
}

function readFailure(string $trackable = 'exports.png'): BumpException
{
    try {
        Bump::total($trackable);
    } catch (BumpException $e) {
        return $e;
    }

    throw new RuntimeException('Bump::total() did not throw.');
}

describe('reading', function () {
    it('returns the totals Bump answers', function () {
        Http::fake(['*' => Http::response(trackableBody())]);

        $total = Bump::total('exports.png');

        expect($total)->toBeInstanceOf(Total::class)
            ->and($total->handle)->toBe('exports.png')
            ->and($total->count)->toBe(3)
            ->and($total->amount)->toBe('59.94')
            ->and($total->cents)->toBe(5994)
            ->and($total->currency)->toBe('EUR')
            ->and($total->lastEventAt)->toBeInstanceOf(CarbonImmutable::class)
            ->and($total->lastEventAt->toIso8601ZuluString())->toBe('2026-10-18T16:48:55Z')
            ->and($total->lastEventAt->tzName)->toBe('UTC');
    });

    it('keeps the amount exactly as Bump answers it', function (string $amount) {
        Http::fake(['*' => Http::response(trackableBody(['amount' => $amount]))]);

        expect(Bump::total('exports.png')->amount)->toBe($amount);
    })->with(['0.00', '-25.00', '92233720368547758.07']);

    it('gives the amount in cents, without float math', function (string $amount, int $cents) {
        Http::fake(['*' => Http::response(trackableBody(['amount' => $amount]))]);

        expect(Bump::total('exports.png')->cents)->toBe($cents);
    })->with([
        ['0.00', 0],
        ['0.07', 7],
        ['-0.05', -5],
        ['-25.00', -2500],
        ['1.10', 110],
        ['59.97', 5997],
        ['92233720368547758.07', PHP_INT_MAX],
        ['-92233720368547758.08', PHP_INT_MIN],
    ]);

    it('reads a trackable without events yet', function () {
        Http::fake(['*' => Http::response(trackableBody(['count' => 0, 'amount' => '0.00', 'last_event_at' => null]))]);

        $total = Bump::total('exports.png');

        expect($total->count)->toBe(0)
            ->and($total->lastEventAt)->toBeNull();
    });

    it('converts a last_event_at with an offset to UTC', function () {
        Http::fake(['*' => Http::response(trackableBody(['last_event_at' => '2026-10-18T18:48:55.123+02:00']))]);

        expect(Bump::total('exports.png')->lastEventAt->format('Y-m-d H:i:s e'))->toBe('2026-10-18 16:48:55 UTC');
    });

    it('sends a GET with the handle encoded in the query string and the token in the header only', function () {
        Http::fake(['*' => Http::response(trackableBody(['handle' => 'maps exported.v2 & more+#']))]);

        Bump::total('maps exported.v2 & more+#');

        Http::assertSent(function (Request $request) {
            return $request->method() === 'GET'
                && $request->url() === 'https://api.bump.example/v1/trackables?handle=maps%20exported.v2%20%26%20more%2B%23'
                && $request->hasHeader('Authorization', 'Bearer bump_test_secret')
                && $request->hasHeader('Accept', 'application/json')
                && str_starts_with($request->header('User-Agent')[0], 'bump-laravel');
        });
        Http::assertSentCount(1);
    });

    it('reads with the live token in production', function () {
        app()['env'] = 'production';
        Http::fake(['*' => Http::response(trackableBody())]);

        Bump::total('exports.png');

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer bump_live_secret'));
    });

    it('waits at most BUMP_SYNC_TIMEOUT', function () {
        config(['bump.sync_timeout' => 7]);
        $options = null;
        Http::fake(['*' => Http::response(trackableBody())]);
        Http::globalMiddleware(function (callable $handler) use (&$options) {
            return function ($request, array $sent) use ($handler, &$options) {
                $options = $sent;

                return $handler($request, $sent);
            };
        });

        Bump::total('exports.png');

        expect($options['timeout'])->toBe(7);
    });

    it('logs nothing and never retries or sleeps', function () {
        Http::fake(['*' => Http::response(null, 503)]);
        Log::spy();

        readFailure();

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    });

    it('takes a BUMP_URL that ends in /v1', function () {
        config(['bump.url' => 'https://api.bump.example/v1/']);
        Http::fake(['*' => Http::response(trackableBody())]);

        Bump::total('exports.png');

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.bump.example/v1/trackables?'));
    });
});

describe('arguments', function () {
    it('throws before any request for a handle Bump could not match', function (string $trackable, string $message) {
        Http::fake();

        expect(fn () => Bump::total($trackable))->toThrow(InvalidArgumentException::class, $message);
        Http::assertNothingSent();
    })->with([
        'empty' => ['', 'An empty handle names no trackable.'],
        'not UTF-8' => ["exports\xB1", "The handle isn't valid UTF-8."],
    ]);
});

describe('failures', function () {
    it('throws when Bump is turned off', function () {
        config(['bump.enabled' => false]);
        Http::fake();

        $e = readFailure();

        expect($e->getMessage())->toBe("Bump is turned off (BUMP_ENABLED), so it can't read totals.")
            ->and($e->status)->toBeNull()
            ->and($e->errorCode)->toBeNull()
            ->and($e->retryAfter)->toBeNull();
        Http::assertNothingSent();
    });

    it('throws without a token for the environment', function (string $env, string $config, string $variable) {
        app()['env'] = $env;
        config([$config => null]);
        Http::fake();

        expect(readFailure()->getMessage())->toBe("Set {$variable} to read totals.");
        Http::assertNothingSent();
    })->with([
        'test' => ['local', 'bump.test_token', 'BUMP_TEST_TOKEN'],
        'live' => ['production', 'bump.live_token', 'BUMP_LIVE_TOKEN'],
    ]);

    it('throws for a BUMP_URL that is not https', function () {
        config(['bump.url' => 'http://api.bump.example']);
        Http::fake();
        Log::spy();

        expect(readFailure()->getMessage())->toBe('BUMP_URL has to use https, except for localhost, 127.0.0.1, ::1 and hosts ending in .test or .localhost.');
        Http::assertNothingSent();
        Log::shouldNotHaveReceived('error');
    });

    it('reads over plain http from a local Bump', function () {
        config(['bump.url' => 'http://bump.test']);
        Http::fake(['*' => Http::response(trackableBody())]);

        expect(Bump::total('exports.png')->count)->toBe(3);
    });

    it('throws with the API error', function (int $status, string $code, string $message) {
        Http::fake(['*' => Http::response(['error' => ['type' => 'invalid_request_error', 'code' => $code, 'message' => $message, 'param' => 'handle']], $status)]);

        $e = readFailure();

        expect($e->getMessage())->toBe("Bump refused the read: {$message}")
            ->and($e->status)->toBe($status)
            ->and($e->errorCode)->toBe($code)
            ->and($e->retryAfter)->toBeNull();
    })->with([
        'unknown handle' => [404, 'resource_missing', 'No trackable with that handle in this project.'],
        'no read ability' => [403, 'missing_ability', "This API token doesn't have the trackables:read ability."],
        'missing handle' => [400, 'parameter_missing', 'The handle parameter is required.'],
        'revoked token' => [401, 'invalid_api_key', 'Invalid API token.'],
    ]);

    it('gives the seconds from a 429 Retry-After, uncapped', function (string $header, int $seconds) {
        Carbon::setTestNow('2026-10-22 12:00:00');
        Http::fake(['*' => Http::response(['error' => ['type' => 'rate_limit_error', 'code' => 'rate_limited', 'message' => 'Too many requests.']], 429, ['Retry-After' => $header])]);

        $e = readFailure();

        expect($e->getMessage())->toBe('Bump refused the read: Too many requests.')
            ->and($e->status)->toBe(429)
            ->and($e->errorCode)->toBe('rate_limited')
            ->and($e->retryAfter)->toBe($seconds);
    })->with([
        'seconds' => ['17', 17],
        'longer than the send cap' => ['3600', 3600],
        'too long for an int' => ['99999999999999999999999', PHP_INT_MAX],
        'HTTP date' => ['Thu, 22 Oct 2026 12:00:40 GMT', 40],
    ]);

    it('has no retryAfter without a readable Retry-After or outside a 429', function (int $status, array $headers) {
        Http::fake(['*' => Http::response(['error' => ['code' => 'x', 'message' => 'No.']], $status, $headers)]);

        expect(readFailure()->retryAfter)->toBeNull();
    })->with([
        '429 without the header' => [429, []],
        '429 with garbage' => [429, ['Retry-After' => 'soon']],
        '503 with the header' => [503, ['Retry-After' => '17']],
    ]);

    it('takes a 429 without an error body', function () {
        Http::fake(['*' => Http::response(null, 429, ['Retry-After' => '5'])]);

        $e = readFailure();

        expect($e->getMessage())->toBe('Bump answered 429.')
            ->and($e->status)->toBe(429)
            ->and($e->errorCode)->toBeNull()
            ->and($e->retryAfter)->toBe(5);
    });

    it('does not follow a redirect', function () {
        Http::fake(['*' => Http::response(null, 308, ['Location' => 'https://elsewhere.example'])]);

        $e = readFailure();

        expect($e->getMessage())->toBe('BUMP_URL answered with a redirect, which is never followed.')
            ->and($e->status)->toBe(308)
            ->and($e->errorCode)->toBeNull();
        Http::assertSentCount(1);
    });

    it('throws when Bump cannot be reached', function () {
        Http::fake(['*' => Http::failedConnection('cURL error 28: Operation timed out')]);

        $e = readFailure();

        expect($e->getMessage())->toBe("Couldn't reach Bump: cURL error 28: Operation timed out")
            ->and($e->status)->toBeNull()
            ->and($e->errorCode)->toBeNull();
    });

    it('throws for a 2xx that is not a trackable', function (mixed $body) {
        Http::fake(['*' => Http::response($body)]);

        $e = readFailure();

        expect($e->getMessage())->toBe("Bump answered with something that isn't a trackable.")
            ->and($e->status)->toBe(200)
            ->and($e->errorCode)->toBeNull();
    })->with([
        'html' => ['<html>Login</html>'],
        'empty' => [''],
        'a list' => [['data' => []]],
        'another object' => [trackableBody(['object' => 'list'])],
        'amount in cents' => [trackableBody(['amount' => 5994])],
        'amount as a float' => [trackableBody(['amount' => 59.94])],
        'amount with one decimal' => [trackableBody(['amount' => '59.9'])],
        'amount without decimals' => [trackableBody(['amount' => '59'])],
        'amount with a comma' => [trackableBody(['amount' => '59,94'])],
        'amount too large to count in cents' => [trackableBody(['amount' => '92233720368547758.08'])],
        'count as text' => [trackableBody(['count' => '3'])],
        'count as a float' => [trackableBody(['count' => 3.5])],
        'no handle' => [array_diff_key(trackableBody(), ['handle' => true])],
        'no currency' => [array_diff_key(trackableBody(), ['currency' => true])],
        'empty currency' => [trackableBody(['currency' => ''])],
        'no last_event_at' => [array_diff_key(trackableBody(), ['last_event_at' => true])],
        'last_event_at not a date' => [trackableBody(['last_event_at' => 'yesterday'])],
        'last_event_at as a number' => [trackableBody(['last_event_at' => 1760806135])],
    ]);

    it('throws for any other answer without an error body', function (int $status) {
        Http::fake(['*' => Http::response('<html>Bad gateway</html>', $status)]);

        $e = readFailure();

        expect($e->getMessage())->toBe("Bump answered {$status}.")
            ->and($e->status)->toBe($status)
            ->and($e->errorCode)->toBeNull()
            ->and($e->retryAfter)->toBeNull();
    })->with([500, 502, 404, 418]);

    it('is a RuntimeException', function () {
        Http::fake(['*' => Http::response(null, 500)]);

        expect(readFailure())->toBeInstanceOf(RuntimeException::class);
    });

    it('never puts the token in the message', function (Closure $answer) {
        Http::fake(['*' => $answer]);

        $e = readFailure();

        expect($e->getMessage())->not->toContain('bump_test_secret')
            ->and($e->getMessage())->toContain('[token]');
    })->with([
        'an error echoing the header' => [fn () => fn (Request $request) => Http::response(['error' => ['code' => 'x', 'message' => 'Got '.$request->header('Authorization')[0]]], 400)],
        'a connection error echoing it' => [fn () => fn () => throw new ConnectionException('Failed with bump_test_secret')],
    ]);
});
