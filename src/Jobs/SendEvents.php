<?php

namespace StickySoftware\Bump\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use StickySoftware\Bump\Api;
use StickySoftware\Bump\Delivery;
use StickySoftware\Bump\SendOutcome;
use Throwable;

/**
 * Releases instead of throwing, so a Bump outage stays out of the app's failed jobs. Never store the
 * token on the job: it would end up in the queue payload.
 *
 * Keep the class name and $events: both are serialized into queued payloads.
 *
 * @internal
 */
class SendEvents implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $tries = 6;

    /**
     * Seconds before each retry when the API sends no Retry-After. Capped at 900 because SQS can't
     * delay a message longer.
     */
    public const BACKOFF = [10, 60, 300, 900, 900];

    /**
     * @param  list<array<string, mixed>>  $events
     */
    public function __construct(public array $events) {}

    public function handle(Api $api, Delivery $delivery): void
    {
        try {
            // The sync queue can't release a job for later, so it retries in-process like sync delivery.
            if ($this->job === null || $this->job instanceof SyncJob) {
                $delivery->sendNow($this->events);

                return;
            }

            $result = $api->send($this->events);

            if ($result->outcome !== SendOutcome::Retry) {
                return;
            }

            if ($this->attempts() >= $this->tries) {
                $this->gaveUp($result->reason);

                return;
            }

            $this->release($result->retryAfter ?? self::BACKOFF[min($this->attempts(), count(self::BACKOFF)) - 1]);
        } catch (Throwable $e) {
            $this->gaveUp($e->getMessage());
        }
    }

    /**
     * Only reached when the worker kills the job, for example on a timeout.
     */
    public function failed(?Throwable $exception): void
    {
        $this->gaveUp($exception?->getMessage() ?? 'The job failed.');

        // A failed job ends its chain, but a chained forget must still run.
        try {
            $this->dispatchNextJobInChain();
        } catch (Throwable $e) {
            Log::error('Bump could not queue a forget request. The identifiable is not forgotten.', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function gaveUp(string $reason): void
    {
        $attempts = $this->attempts();

        Log::error('Bump gave up on '.Api::events(count($this->events)).' after '.$attempts.' '.Str::plural('attempt', $attempts).'.', [
            'reason' => $reason,
        ]);
    }
}
