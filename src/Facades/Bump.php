<?php

namespace StickySoftware\Bump\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void track(string $trackable, mixed $identifiable = null, int|list<int>|null $count = null, int|float|string|list<int|float|string>|null $amount = null, ?\DateTimeInterface $occurredAt = null, ?string $note = null, ?bool $activity = null)
 * @method static void adjust(string $trackable, mixed $identifiable = null, int|list<int>|null $count = 0, int|float|string|list<int|float|string>|null $amount = null, ?\DateTimeInterface $occurredAt = null, ?string $note = null, ?bool $activity = false)
 * @method static void flush()
 *
 * @see \StickySoftware\Bump\Bump
 */
class Bump extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \StickySoftware\Bump\Bump::class;
    }
}
