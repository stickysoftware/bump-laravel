<?php

namespace StickySoftware\Bump;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * One request to the ingest API, and the log lines that belong to its answer.
 */
final class Api
{
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
     * @param  list<array<string, mixed>>  $events
     */
    public function send(array $events, bool $backfill = false, ?int $timeout = null): SendResult
    {
        $token = $this->token();

        if ($token === null) {
            Log::error('Bump dropped '.count($events).' events: '.$this->tokenVariable().' is not set.');

            return SendResult::failed('No token.');
        }

        $request = Http::withToken($token)->acceptJson();

        if ($timeout !== null) {
            $request->timeout($timeout);
        }

        $startedAt = Carbon::now();

        try {
            $response = $request->post(rtrim((string) config('bump.url'), '/').'/events', [
                'backfill' => $backfill,
                'events' => $events,
            ]);
        } catch (ConnectionException $e) {
            $this->logSlow($startedAt, $timeout);

            return SendResult::retry(null, $e->getMessage());
        }

        $this->logSlow($startedAt, $timeout);

        if ($response->successful()) {
            $results = $response->json('data');

            // Something between here and the API answered instead (a proxy page, a captive portal).
            // The event ids make sending the batch again safe.
            if (! is_array($results) || ! array_is_list($results)) {
                return SendResult::retry(null, "HTTP {$response->status()} without a result list.");
            }

            $this->logRejected($events, $results);

            return SendResult::delivered($results);
        }

        if ($response->status() === 429 || $response->serverError()) {
            return SendResult::retry($this->retryAfter($response), "HTTP {$response->status()}");
        }

        Log::error('Bump refused '.count($events).' events.', [
            'status' => $response->status(),
            'code' => $response->json('error.code'),
            'message' => $response->json('error.message'),
            'param' => $response->json('error.param'),
        ]);

        return SendResult::failed("HTTP {$response->status()}");
    }

    /**
     * Results come one per event after expansion, so an event with a count or amount list owns as
     * many results as it has values.
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

    /**
     * The signal for tuning BUMP_SYNC_TIMEOUT: these lines appearing means the timeout is close to what
     * Bump actually needs.
     */
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

    private function retryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        if ($header === '') {
            return null;
        }

        if (ctype_digit($header)) {
            return (int) $header;
        }

        $date = strtotime($header);

        return $date === false ? null : max(0, $date - Carbon::now()->getTimestamp());
    }
}
