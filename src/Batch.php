<?php

namespace StickySoftware\Bump;

/**
 * @internal
 */
final class Batch
{
    public const MAX_EVENTS = 100;

    /**
     * The API counts each value of a count or amount list as one event, also toward the 100 per request.
     * A cents list becomes the amount list.
     *
     * @param  array<string, mixed>  $event
     */
    public static function size(array $event): int
    {
        return max(
            1,
            is_array($event['count'] ?? null) ? count($event['count']) : 0,
            is_array($event['amount'] ?? null) ? count($event['amount']) : 0,
            is_array($event['cents'] ?? null) ? count($event['cents']) : 0,
        );
    }
}
