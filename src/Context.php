<?php

namespace StickySoftware\Bump;

/**
 * Where a call happens decides its activity default: a person acts in a web request, the system acts
 * in queued jobs and console commands. A job is tracked by its own events instead of by the console
 * check, so a job run by the sync queue inside a web request still counts as a job.
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
