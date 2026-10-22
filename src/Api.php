<?php

namespace StickySoftware\Bump;

use Composer\InstalledVersions;
use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Psr7\Exception\MalformedUriException;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * @internal
 */
final class Api
{
    private const PACKAGE = 'stickysoftware/bump-laravel';

    /**
     * Seconds. SQS can't delay a message longer, and a broken or hostile answer must not park a job for days.
     */
    public const MAX_RETRY_AFTER = 900;

    private const HTTPS_RULE = 'BUMP_URL has to use https, except for localhost, 127.0.0.1, ::1 and hosts ending in .test or .localhost.';

    private static bool $reportedVersionedUrl = false;

    private static bool $reportedInsecureUrl = false;

    private static ?string $userAgent = null;

    public function token(): ?string
    {
        $token = config(app()->isProduction() ? 'bump.live_token' : 'bump.test_token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function tokenVariable(): string
    {
        return app()->isProduction() ? 'BUMP_LIVE_TOKEN' : 'BUMP_TEST_TOKEN';
    }

    /**
     * Plain http would send the token in the clear, so it's only for a Bump on this machine or a local
     * dev domain. Parsed the way the HTTP client parses it, so both agree on the host.
     *
     * @return string|null why the package won't send to BUMP_URL, null when it will
     */
    public function urlRefusal(): ?string
    {
        try {
            $uri = new Uri((string) config('bump.url'));
        } catch (MalformedUriException) {
            return self::HTTPS_RULE;
        }

        if ($uri->getScheme() === 'https') {
            return null;
        }

        $host = trim($uri->getHost(), '[]');

        $local = in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.localhost');

        return $local ? null : self::HTTPS_RULE;
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    public function send(array $events, bool $backfill = false, ?int $timeout = null): SendResult
    {
        $token = $this->token();

        if ($token === null) {
            Log::error('Bump dropped '.self::events(count($events)).': '.$this->tokenVariable().' is not set.');

            return SendResult::failed('No token.');
        }

        if (! $this->urlAllowed()) {
            return SendResult::failed('BUMP_URL is not https.');
        }

        $response = $this->post($token, 'events', ['backfill' => $backfill, 'events' => $events], $timeout);

        if ($response instanceof SendResult) {
            return $response;
        }

        if ($response->successful()) {
            $results = $response->json('data');

            // A 200 without results came from something else, like a captive portal. The event ids
            // make a resend safe.
            if (! is_array($results) || ! array_is_list($results)) {
                return SendResult::retry(null, "HTTP {$response->status()} without a result list.");
            }

            $this->logRejected($events, $results);

            return SendResult::delivered($results);
        }

        if ($response->redirect()) {
            Log::error('Bump dropped '.self::events(count($events)).': '.$this->redirected($response));

            return SendResult::failed("HTTP {$response->status()}");
        }

        if ($this->retryable($response)) {
            return SendResult::retry($this->retryAfter($response), "HTTP {$response->status()}");
        }

        Log::error('Bump refused '.self::events(count($events)).'.', $this->error($response));

        return SendResult::failed("HTTP {$response->status()}");
    }

    public function forget(string $handle, ?int $timeout = null): SendResult
    {
        $token = $this->token();

        if ($token === null) {
            Log::error('Bump could not forget an identifiable: '.$this->tokenVariable().' is not set.');

            return SendResult::failed('No token.');
        }

        if (! $this->urlAllowed()) {
            return SendResult::failed('BUMP_URL is not https.');
        }

        // In the body, never the URL: URLs end up in access logs.
        $response = $this->post($token, 'identifiables/forget', ['identifiable' => $handle], $timeout);

        if ($response instanceof SendResult) {
            return $response;
        }

        if ($response->successful()) {
            return $response->json('forgotten') === true
                ? SendResult::delivered([])
                : SendResult::retry(null, "HTTP {$response->status()} without a forget result.");
        }

        // The param tells this apart from the 404 for an unknown URL, like a wrong BUMP_URL.
        if ($response->status() === 404 && $response->json('error.code') === 'resource_missing' && $response->json('error.param') === 'identifiable') {
            Log::debug('Bump had no identifiable with that handle to forget.');

            return SendResult::delivered([]);
        }

        if ($response->status() === 403 && $response->json('error.code') === 'missing_ability') {
            Log::warning('Bump could not forget an identifiable: '.$this->tokenVariable().' can\'t forget identifiables. Create a token with "Can forget identifiables" turned on.');

            return SendResult::failed('HTTP 403');
        }

        if ($response->redirect()) {
            Log::error('Bump could not forget an identifiable: '.$this->redirected($response));

            return SendResult::failed("HTTP {$response->status()}");
        }

        if ($this->retryable($response)) {
            return SendResult::retry($this->retryAfter($response), "HTTP {$response->status()}");
        }

        Log::error('Bump refused to forget an identifiable.', $this->error($response));

        return SendResult::failed("HTTP {$response->status()}");
    }

    /**
     * Tests reset the once-per-process log line.
     */
    public static function forgetReportedWarnings(): void
    {
        self::$reportedVersionedUrl = false;
        self::$reportedInsecureUrl = false;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return Response|SendResult the answer, or a retry when there was none
     */
    private function post(#[\SensitiveParameter] string $token, string $path, array $body, ?int $timeout): Response|SendResult
    {
        // No redirects: the https check vets BUMP_URL, not where it redirects to.
        $request = Http::withToken($token)->withUserAgent(self::userAgent())->acceptJson()->withoutRedirecting();

        if ($timeout !== null) {
            $request->timeout($timeout);
        }

        $startedAt = Carbon::now();

        try {
            $response = $request->post($this->url($path), $body);
        } catch (ConnectionException $e) {
            $this->logSlow($startedAt, $timeout);

            return SendResult::retry(null, $e->getMessage());
        }

        $this->logSlow($startedAt, $timeout);

        return $response;
    }

    /**
     * BUMP_URL is the host and the package adds /v1. A URL that ends in /v1 works too.
     */
    private function url(string $path): string
    {
        $base = rtrim((string) config('bump.url'), '/');

        if (str_ends_with($base, '/v1')) {
            $base = substr($base, 0, -strlen('/v1'));

            if (! self::$reportedVersionedUrl) {
                self::$reportedVersionedUrl = true;

                Log::debug("Bump's URL ends in /v1, which the package adds itself. Set BUMP_URL to {$base}.");
            }
        }

        return "{$base}/v1/{$path}";
    }

    private function urlAllowed(): bool
    {
        $refusal = $this->urlRefusal();

        if ($refusal === null) {
            return true;
        }

        if (! self::$reportedInsecureUrl) {
            self::$reportedInsecureUrl = true;

            Log::error('Bump sends nothing: '.$refusal);
        }

        return false;
    }

    private function redirected(Response $response): string
    {
        return "BUMP_URL answered with a redirect (HTTP {$response->status()}), which the package doesn't follow.";
    }

    private function retryable(Response $response): bool
    {
        return $response->status() === 429 || $response->serverError();
    }

    /**
     * @return array{status: int, code: mixed, message: mixed, param: mixed}
     */
    private function error(Response $response): array
    {
        return [
            'status' => $response->status(),
            'code' => $response->json('error.code'),
            'message' => $response->json('error.message'),
            'param' => $response->json('error.param'),
        ];
    }

    /**
     * The API answers one result per value of a count or amount list.
     *
     * @param  list<array<string, mixed>>  $events
     * @param  list<mixed>  $results
     */
    private function logRejected(array $events, array $results): void
    {
        $position = 0;

        foreach ($events as $event) {
            $size = Batch::size($event);

            foreach (array_slice($results, $position, $size) as $result) {
                if (is_array($result) && ($result['status'] ?? null) === 'rejected') {
                    Log::warning('Bump rejected an event.', [
                        'id' => $event['id'] ?? null,
                        'trackable' => $event['trackable'] ?? null,
                        'param' => $result['error']['param'] ?? null,
                        'message' => $result['error']['message'] ?? null,
                    ]);
                }
            }

            $position += $size;
        }
    }

    private function logSlow(Carbon $startedAt, ?int $timeout): void
    {
        if ($timeout === null) {
            return;
        }

        $seconds = $startedAt->diffInMilliseconds(Carbon::now()) / 1000;

        if ($seconds >= $timeout / 2) {
            Log::warning(sprintf('Bump took %.1fs to answer (sync timeout %ds).', $seconds, $timeout));
        }
    }

    /**
     * Seconds or an HTTP date, capped at MAX_RETRY_AFTER. Anything else falls back to the package's own backoff.
     */
    private function retryAfter(Response $response): ?int
    {
        $header = trim($response->header('Retry-After'));

        if ($header === '') {
            return null;
        }

        if (ctype_digit($header)) {
            // Compared as text first: a long number overflows an int.
            return strlen(ltrim($header, '0')) > strlen((string) self::MAX_RETRY_AFTER)
                ? self::MAX_RETRY_AFTER
                : min((int) $header, self::MAX_RETRY_AFTER);
        }

        $date = DateTimeImmutable::createFromFormat('!D, d M Y H:i:s \G\M\T', $header, new DateTimeZone('UTC'));

        if ($date === false || DateTimeImmutable::getLastErrors() !== false) {
            return null;
        }

        return min(max(0, $date->getTimestamp() - Carbon::now()->getTimestamp()), self::MAX_RETRY_AFTER);
    }

    public static function events(int $count): string
    {
        return $count.' '.Str::plural('event', $count);
    }

    private static function userAgent(): string
    {
        if (self::$userAgent !== null) {
            return self::$userAgent;
        }

        try {
            $version = InstalledVersions::isInstalled(self::PACKAGE) ? InstalledVersions::getPrettyVersion(self::PACKAGE) : null;
        } catch (Throwable) {
            $version = null;
        }

        return self::$userAgent = is_string($version) && $version !== ''
            ? 'bump-laravel/'.ltrim($version, 'v')
            : 'bump-laravel';
    }
}
