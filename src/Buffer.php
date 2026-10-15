<?php

namespace StickySoftware\Bump;

/**
 * Events recorded during one request, command or job. Bound as a scoped instance, so every Octane
 * request and every queued job starts with an empty one.
 */
final class Buffer
{
    /** @var list<array<string, mixed>> */
    private array $events = [];

    private int $size = 0;

    /**
     * @param  array<string, mixed>  $event
     */
    public function push(array $event): void
    {
        $this->events[] = $event;
        $this->size += Batch::size($event);
    }

    public function size(): int
    {
        return $this->size;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pull(): array
    {
        $events = $this->events;

        $this->events = [];
        $this->size = 0;

        return $events;
    }
}
