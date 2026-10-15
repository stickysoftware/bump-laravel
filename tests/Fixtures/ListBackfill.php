<?php

namespace StickySoftware\Bump\Tests\Fixtures;

use StickySoftware\Bump\Contracts\Backfill;

class ListBackfill implements Backfill
{
    /** @var iterable<mixed> */
    public static iterable $events = [];

    public function events(): iterable
    {
        return static::$events;
    }
}
