<?php

namespace StickySoftware\Bump\Backfill;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

/**
 * The stretch of history a backfill sends. `until` is when live counting started: events after it
 * were already counted live under package-generated ids, so sending them again counts them twice.
 */
final readonly class BackfillWindow
{
    /**
     * Both in the app timezone: query bindings format a date's wall clock without converting it,
     * so a bound in any other timezone would compare against the wrong time.
     */
    public CarbonImmutable $until;

    public ?CarbonImmutable $since;

    public function __construct(DateTimeInterface $until, ?DateTimeInterface $since = null)
    {
        $timezone = (string) config('app.timezone');

        $this->until = CarbonImmutable::instance($until)->setTimezone($timezone);
        $this->since = $since === null ? null : CarbonImmutable::instance($since)->setTimezone($timezone);

        if ($this->since !== null && $this->since->greaterThanOrEqualTo($this->until)) {
            throw new InvalidArgumentException('A backfill window needs since before until.');
        }
    }

    /**
     * @template TQuery of Builder|EloquentBuilder<*>
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function constrain(Builder|EloquentBuilder $query, string $column): Builder|EloquentBuilder
    {
        // Since inclusive, until exclusive: consecutive windows never overlap or leave a gap.
        $query->where($column, '<', $this->until);

        if ($this->since !== null) {
            $query->where($column, '>=', $this->since);
        }

        return $query;
    }
}
