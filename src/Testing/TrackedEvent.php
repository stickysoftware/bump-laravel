<?php

namespace StickySoftware\Bump\Testing;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use StickySoftware\Bump\Identifiables;

/**
 * One track() or adjust() call recorded by Bump::fake(). Null means the call left the field out and
 * Bump's default applies: count 1, amount 0, activity by the trackable's setting, a generated id.
 */
final readonly class TrackedEvent
{
    /**
     * @internal
     *
     * @param  list<mixed>  $identifiables  handles as sent: a string, or a handle and label map
     * @param  int|list<int>|null  $count
     * @param  int|float|string|list<int|float|string>|null  $amount
     */
    public function __construct(
        public string $trackable,
        public array $identifiables,
        public int|array|null $count,
        public int|float|string|array|null $amount,
        public CarbonImmutable $occurredAt,
        public ?string $note,
        public ?bool $activity,
        public bool $adjustment,
        public ?string $id = null,
    ) {}

    public function hasIdentifiable(Model|string $identifiable): bool
    {
        $handle = $identifiable instanceof Model ? Identifiables::handle($identifiable) : $identifiable;

        foreach ($this->identifiables as $sent) {
            if ($sent === $handle || (is_array($sent) && ($sent['handle'] ?? null) === $handle)) {
                return true;
            }
        }

        return false;
    }
}
