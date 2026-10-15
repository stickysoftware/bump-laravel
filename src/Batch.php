<?php

namespace StickySoftware\Bump;

final class Batch
{
    public const MAX_EVENTS = 100;

    /**
     * How many events the API counts for this one: a count or amount list expands into one event per
     * value, and the 100-per-request limit counts after expansion.
     *
     * @param  array<string, mixed>  $event
     */
    public static function size(array $event): int
    {
        return max(
            1,
            is_array($event['count'] ?? null) ? count($event['count']) : 0,
            is_array($event['amount'] ?? null) ? count($event['amount']) : 0,
        );
    }
}
