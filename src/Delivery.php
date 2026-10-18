<?php

namespace StickySoftware\Bump;

use Closure;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use StickySoftware\Bump\Jobs\ForgetIdentifiable;
use StickySoftware\Bump\Jobs\SendEvents;
use Throwable;

/**
 * @internal
 */
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
        if ($this->sync()) {
            $this->sendNow($events, timeout: $this->syncTimeout());

            return;
        }

        try {
            app(Dispatcher::class)->dispatch($this->onBumpQueue(new SendEvents($events)));
        } catch (Throwable $e) {
            $this->logLost($events, $e);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $events  recorded before the forget, so they go out ahead of it
     */
    public function forget(array $events, string $handle): void
    {
        if ($this->sync()) {
            if ($events !== []) {
                $this->sendNow($events, timeout: $this->syncTimeout());
            }

            $this->forgetNow($handle, timeout: $this->syncTimeout());

            return;
        }

        $forget = new ForgetIdentifiable($handle);

        try {
            if ($events === []) {
                app(Dispatcher::class)->dispatch($this->onBumpQueue($forget));

                return;
            }

            // Chained, so the forget runs only after the events are sent.
            Bus::chain([new SendEvents($events), $forget])
                ->onConnection(config('bump.queue.connection'))
                ->onQueue(config('bump.queue.name'))
                ->dispatch();
        } catch (Throwable $e) {
            if ($events !== []) {
                $this->logLost($events, $e);
            }

            Log::error('Bump could not queue a forget request. The identifiable is not forgotten.', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Retries in-process. A Retry-After longer than $maxWait gives up rather than hold the request.
     *
     * @param  list<array<string, mixed>>  $events
     */
    public function sendNow(array $events, bool $backfill = false, int $attempts = 3, int $maxWait = 10, ?int $timeout = null): SendResult
    {
        [$result, $attempt] = $this->attempt(fn () => $this->api->send($events, $backfill, $timeout), $attempts, $maxWait);

        if ($result->outcome !== SendOutcome::Retry) {
            return $result;
        }

        Log::error('Bump gave up on '.Api::events(count($events)).' after '.$attempt.' '.Str::plural('attempt', $attempt).'.', [
            'reason' => $result->reason,
            'retry_after' => $result->retryAfter,
        ]);

        return SendResult::failed($result->reason);
    }

    public function forgetNow(string $handle, int $attempts = 3, int $maxWait = 10, ?int $timeout = null): SendResult
    {
        [$result, $attempt] = $this->attempt(fn () => $this->api->forget($handle, $timeout), $attempts, $maxWait);

        if ($result->outcome !== SendOutcome::Retry) {
            return $result;
        }

        Log::error('Bump gave up on forgetting an identifiable after '.$attempt.' '.Str::plural('attempt', $attempt).'.', [
            'reason' => $result->reason,
            'retry_after' => $result->retryAfter,
        ]);

        return SendResult::failed($result->reason);
    }

    /**
     * @param  Closure(): SendResult  $send
     * @return array{SendResult, int} the last result, still Retry when attempts ran out, and the attempts made
     */
    private function attempt(Closure $send, int $attempts, int $maxWait): array
    {
        for ($attempt = 1; ; $attempt++) {
            $result = $send();

            if ($result->outcome !== SendOutcome::Retry) {
                return [$result, $attempt];
            }

            $wait = $result->retryAfter ?? self::BACKOFF[min($attempt, count(self::BACKOFF)) - 1];

            if ($attempt >= $attempts || $wait > $maxWait) {
                return [$result, $attempt];
            }

            Sleep::for($wait)->seconds();
        }
    }

    /**
     * @template TJob of SendEvents|ForgetIdentifiable
     *
     * @param  TJob  $job
     * @return TJob
     */
    private function onBumpQueue(SendEvents|ForgetIdentifiable $job): SendEvents|ForgetIdentifiable
    {
        return $job->onConnection(config('bump.queue.connection'))->onQueue(config('bump.queue.name'));
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function logLost(array $events, Throwable $e): void
    {
        $count = count($events);

        Log::error('Bump could not queue '.Api::events($count).'. '.($count === 1 ? 'It is' : 'They are').' lost.', [
            'exception' => $e->getMessage(),
        ]);
    }

    private function sync(): bool
    {
        return config('bump.delivery') === 'sync';
    }

    private function syncTimeout(): int
    {
        return (int) config('bump.sync_timeout');
    }
}
