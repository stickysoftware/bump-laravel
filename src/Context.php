<?php

namespace StickySoftware\Bump;

/**
 * Jobs are counted through queue events, not runningInConsole(), so a job the sync queue runs inside
 * a web request still counts as a job.
 *
 * @internal
 */
final class Context
{
    private int $runningJobs = 0;

    public function jobStarted(): void
    {
        $this->runningJobs++;
    }

    public function jobEnded(): void
    {
        $this->runningJobs = max(0, $this->runningJobs - 1);
    }

    public function isWebRequest(): bool
    {
        return $this->runningJobs === 0 && ! app()->runningInConsole();
    }
}
