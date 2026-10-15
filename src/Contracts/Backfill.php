<?php

namespace StickySoftware\Bump\Contracts;

interface Backfill
{
    /**
     * Events in the API's item shape. Each needs an `id` derived from the app's own data (like
     * "order-1042"), so running the backfill again skips what was already sent. `identifiable` takes
     * models like Bump::track() does, `occurred_at` takes a DateTimeInterface.
     *
     * @return iterable<array<string, mixed>>
     */
    public function events(): iterable;
}
