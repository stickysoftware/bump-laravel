<?php

namespace StickySoftware\Bump\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use StickySoftware\Bump\Facades\Bump;

class TrackingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public bool $throws = false, public ?bool $activity = null) {}

    public function handle(): void
    {
        Bump::track('orders', activity: $this->activity);

        if ($this->throws) {
            throw new RuntimeException('The job broke after tracking.');
        }
    }
}
