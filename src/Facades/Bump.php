<?php

namespace StickySoftware\Bump\Facades;

use Illuminate\Support\Facades\Facade;
use StickySoftware\Bump\Testing\BumpFake;
use StickySoftware\Bump\Tracker;

/**
 * @method static void track(string $trackable, mixed $identifiable = null, int|list<int>|null $count = null, int|float|string|list<int|float|string>|null $amount = null, ?\DateTimeInterface $occurredAt = null, ?string $note = null, ?bool $activity = null, ?string $id = null, int|list<int>|null $cents = null)
 * @method static void adjust(string $trackable, mixed $identifiable = null, int|list<int>|null $count = 0, int|float|string|list<int|float|string>|null $amount = null, ?\DateTimeInterface $occurredAt = null, ?string $note = null, ?bool $activity = false, ?string $id = null, int|list<int>|null $cents = null)
 * @method static void forget(\Illuminate\Database\Eloquent\Model|string|int $identifiable)
 * @method static void flush()
 * @method static \StickySoftware\Bump\Total total(string $trackable)
 * @method static \StickySoftware\Bump\Testing\BumpFake withTotal(string $trackable, int $count = 0, int|float|string $amount = 0, string $currency = 'EUR', ?\DateTimeInterface $lastEventAt = null)
 * @method static void assertTracked(string $trackable, (callable(\StickySoftware\Bump\Testing\TrackedEvent): bool)|null $callback = null)
 * @method static void assertTrackedTimes(string $trackable, int $times = 1)
 * @method static void assertNotTracked(string $trackable, (callable(\StickySoftware\Bump\Testing\TrackedEvent): bool)|null $callback = null)
 * @method static void assertNothingTracked()
 * @method static \Illuminate\Support\Collection<int, \StickySoftware\Bump\Testing\TrackedEvent> tracked(string $trackable, (callable(\StickySoftware\Bump\Testing\TrackedEvent): bool)|null $callback = null)
 * @method static void assertForgotten(\Illuminate\Database\Eloquent\Model|string|int $identifiable)
 * @method static void assertNotForgotten(\Illuminate\Database\Eloquent\Model|string|int $identifiable)
 * @method static \Illuminate\Support\Collection<int, string> forgotten()
 *
 * @see Tracker
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
        return Tracker::class;
    }
}
