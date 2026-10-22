<?php

namespace StickySoftware\Bump\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
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
 * Never log the handle: it's what is being erased.
 *
 * Keep the class name and $handle: both are serialized into queued payloads.
 *
 * @internal
 */
class ForgetIdentifiable implements ShouldBeEncrypted, ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $tries = 6;

    public function __construct(public string $handle) {}

    public function handle(Api $api, Delivery $delivery): void
    {
        try {
            if ($this->job === null || $this->job instanceof SyncJob) {
                $delivery->forgetNow($this->handle);

                return;
            }

            $result = $api->forget($this->handle);

            if ($result->outcome !== SendOutcome::Retry) {
                return;
            }

            if ($this->attempts() >= $this->tries) {
                $this->gaveUp($result->reason);

                return;
            }

            $this->release($result->retryAfter ?? SendEvents::BACKOFF[min($this->attempts(), count(SendEvents::BACKOFF)) - 1]);
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
        $attempts = $this->attempts();

        Log::error('Bump gave up on forgetting an identifiable after '.$attempts.' '.Str::plural('attempt', $attempts).'.', [
            'reason' => $reason,
        ]);
    }
}
