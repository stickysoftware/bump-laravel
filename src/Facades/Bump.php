<?php

namespace StickySoftware\Bump\Facades;

use Illuminate\Support\Facades\Facade;
use StickySoftware\Bump\Testing\BumpFake;

/**
 * @method static void track(string $trackable, mixed $identifiable = null, int|list<int>|null $count = null, int|float|string|list<int|float|string>|null $amount = null, ?\DateTimeInterface $occurredAt = null, ?string $note = null, ?bool $activity = null)
 * @method static void adjust(string $trackable, mixed $identifiable = null, int|list<int>|null $count = 0, int|float|string|list<int|float|string>|null $amount = null, ?\DateTimeInterface $occurredAt = null, ?string $note = null, ?bool $activity = false)
 * @method static void flush()
 * @method static void assertTracked(string $trackable, (callable(\StickySoftware\Bump\Testing\TrackedEvent): bool)|null $callback = null)
 * @method static void assertTrackedTimes(string $trackable, int $times = 1)
 * @method static void assertNotTracked(string $trackable, (callable(\StickySoftware\Bump\Testing\TrackedEvent): bool)|null $callback = null)
 * @method static void assertNothingTracked()
 * @method static \Illuminate\Support\Collection<int, \StickySoftware\Bump\Testing\TrackedEvent> tracked(string $trackable, (callable(\StickySoftware\Bump\Testing\TrackedEvent): bool)|null $callback = null)
 *
 * @see \StickySoftware\Bump\Bump
 * @see BumpFake
 */
class Bump extends Facade
{
    public static function fake(): BumpFake
    {
        static::swap($fake = new BumpFake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return \StickySoftware\Bump\Bump::class;
    }
}
