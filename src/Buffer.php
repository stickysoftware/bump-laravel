<?php

namespace StickySoftware\Bump;

/**
 * @internal
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
