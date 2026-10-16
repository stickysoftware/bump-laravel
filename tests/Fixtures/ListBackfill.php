<?php

namespace StickySoftware\Bump\Tests\Fixtures;

use StickySoftware\Bump\Backfill\BackfillWindow;
use StickySoftware\Bump\Contracts\Backfill;

class ListBackfill implements Backfill
{
    /** @var iterable<mixed> */
    public static iterable $events = [];

    public static ?BackfillWindow $window = null;

    public function events(BackfillWindow $window): iterable
    {
        static::$window = $window;

        return static::$events;
    }
}
