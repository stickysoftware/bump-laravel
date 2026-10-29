<?php

namespace StickySoftware\Bump\Contracts;

use StickySoftware\Bump\Backfill\BackfillWindow;

interface Backfill
{
    /**
     * Events in the API's item shape, from inside the window: constrain the query with
     * `$window->constrain($query, 'paid_at')`. Each needs an `id` derived from the app's own data
     * (like "orders-1042"), so running the backfill again skips what was already sent. `identifiable`
     * takes models like Bump::track() does. `occurred_at` is required and takes a DateTimeInterface.
     * `amount` and `cents` take what `amount:` and `cents:` take on Bump::track(), one of the two.
     *
     * @return iterable<array<string, mixed>>
     */
    public function events(BackfillWindow $window): iterable;
}
