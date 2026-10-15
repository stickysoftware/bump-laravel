<?php

namespace StickySoftware\Bump;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use StickySoftware\Bump\Jobs\SendEvents;
use Throwable;

final class Delivery
{
    /** Seconds between attempts when the API sends no Retry-After. */
    private const BACKOFF = [1, 5, 15, 30];

    public function __construct(private readonly Api $api) {}

    /**
     * @param  list<array<string, mixed>>  $events
     */
    public function deliver(array $events): void
    {
        if (config('bump.delivery') === 'sync') {
            $this->sendNow($events);

            return;
        }

        try {
            app(Dispatcher::class)->dispatch(
                (new SendEvents($events))
                    ->onConnection(config('bump.queue.connection'))
                    ->onQueue(config('bump.queue.name')),
            );
        } catch (Throwable $e) {
            Log::error('Bump could not queue '.count($events).' events, they are lost.', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Sends right away and retries in-process. A web request or command waits at most $maxWait seconds
     * per retry: a longer Retry-After gives up instead of holding the process.
     *
     * @param  list<array<string, mixed>>  $events
     */
    public function sendNow(array $events, bool $backfill = false, int $attempts = 3, int $maxWait = 10): SendResult
    {
        for ($attempt = 1; ; $attempt++) {
            $result = $this->api->send($events, $backfill);

            if ($result->outcome !== SendOutcome::Retry) {
                return $result;
            }

            $wait = $result->retryAfter ?? self::BACKOFF[min($attempt, count(self::BACKOFF)) - 1];

            if ($attempt >= $attempts || $wait > $maxWait) {
                break;
            }

            Sleep::for($wait)->seconds();
        }

        Log::error('Bump gave up on '.count($events)." events after {$attempt} attempts.", [
            'reason' => $result->reason,
            'retry_after' => $result->retryAfter,
        ]);

        return SendResult::failed($result->reason);
    }
}
