<?php

namespace StickySoftware\Bump\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Log;
use StickySoftware\Bump\Api;
use StickySoftware\Bump\Delivery;
use StickySoftware\Bump\SendOutcome;
use Throwable;

/**
 * Retries are released by the job itself rather than thrown, so a Bump outage never shows up in the
 * app's failed jobs or exception tracker. The token is read when the job runs and never stored in
 * the queue payload.
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
    }

    private function gaveUp(string $reason): void
    {
        Log::error('Bump gave up on '.count($this->events).' events after '.$this->attempts().' attempts.', [
            'reason' => $reason,
        ]);
    }
}
