<?php

namespace StickySoftware\Bump\Testing;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\Fake;
use InvalidArgumentException;
use PHPUnit\Framework\Assert as PHPUnit;
use StickySoftware\Bump\Amounts;
use StickySoftware\Bump\Batch;
use StickySoftware\Bump\EventRules;
use StickySoftware\Bump\Exceptions\BumpException;
use StickySoftware\Bump\Identifiables;
use StickySoftware\Bump\Total;
use Throwable;

/**
 * Records with or without a token or BUMP_ENABLED. A call real delivery would drop is dropped here
 * too, with the same warning. total() answers what withTotal() set, and throws like a 404 otherwise.
 */
final class BumpFake implements Fake
{
    /** @var list<TrackedEvent> */
    private array $events = [];

    /** @var array<string, list<string>> trackable => why its calls were dropped */
    private array $dropped = [];

    /** @var list<string> */
    private array $forgotten = [];

    /** @var list<string> */
    private array $droppedForgets = [];

    /** @var array<string, Total> */
    private array $totals = [];

    /**
     * @param  int|list<int>|null  $count
     * @param  int|float|string|list<int|float|string>|null  $amount
     * @param  int|list<int>|null  $cents
     */
    public function track(
        string $trackable,
        mixed $identifiable = null,
        int|array|null $count = null,
        int|float|string|array|null $amount = null,
        ?DateTimeInterface $occurredAt = null,
        ?string $note = null,
        ?bool $activity = null,
        ?string $id = null,
        int|array|null $cents = null,
    ): void {
        $this->record(false, $trackable, $identifiable, $count, $amount, $occurredAt, $note, $activity, $id, $cents);
    }

    /**
     * @param  int|list<int>|null  $count
     * @param  int|float|string|list<int|float|string>|null  $amount
     * @param  int|list<int>|null  $cents
     */
    public function adjust(
        string $trackable,
        mixed $identifiable = null,
        int|array|null $count = 0,
        int|float|string|array|null $amount = null,
        ?DateTimeInterface $occurredAt = null,
        ?string $note = null,
        ?bool $activity = false,
        ?string $id = null,
        int|array|null $cents = null,
    ): void {
        $this->record(true, $trackable, $identifiable, $count, $amount, $occurredAt, $note, $activity, $id, $cents);
    }

    public function flush(): void {}

    public function forget(Model|string|int $identifiable): void
    {
        try {
            $this->forgotten[] = Identifiables::handleToForget($identifiable);
        } catch (Throwable $e) {
            Log::warning('Bump could not forget an identifiable.', ['exception' => $e->getMessage()]);

            $this->droppedForgets[] = $e->getMessage();
        }
    }

    public function withTotal(
        string $trackable,
        int $count = 0,
        int|float|string $amount = 0,
        string $currency = 'EUR',
        ?DateTimeInterface $lastEventAt = null,
    ): self {
        EventRules::checkTrackable($trackable);

        $this->totals[$trackable] = new Total(
            handle: $trackable,
            count: $count,
            amount: self::amount($amount),
            currency: $currency,
            lastEventAt: $lastEventAt === null ? null : CarbonImmutable::instance($lastEventAt)->utc(),
        );

        return $this;
    }

    public function total(string $trackable): Total
    {
        EventRules::checkTrackable($trackable);

        return $this->totals[$trackable] ?? throw new BumpException(
            'Bump refused the read: No trackable with that handle in this project.',
            404,
            'resource_missing',
        );
    }

    public function assertForgotten(Model|string|int $identifiable): void
    {
        $handle = $this->handle($identifiable);

        PHPUnit::assertContains(
            $handle,
            $this->forgotten,
            "Expected [{$handle}] to be forgotten, but it was not.".$this->droppedForgetsNote(),
        );
    }

    public function assertNotForgotten(Model|string|int $identifiable): void
    {
        $handle = $this->handle($identifiable);

        PHPUnit::assertNotContains($handle, $this->forgotten, "Expected [{$handle}] not to be forgotten, but it was.");
    }

    /**
     * @return Collection<int, string> handles, in the order forget() was called
     */
    public function forgotten(): Collection
    {
        return collect($this->forgotten);
    }

    /**
     * @param  (callable(TrackedEvent): bool)|null  $callback
     */
    public function assertTracked(string $trackable, ?callable $callback = null): void
    {
        $times = $this->tracked($trackable)->count();

        $message = match (true) {
            $callback === null => "Expected [{$trackable}] to be tracked, but it was not.",
            $times === 0 => "Expected [{$trackable}] to be tracked matching the callback, but it was not tracked.",
            default => "Expected [{$trackable}] to be tracked matching the callback, but it was tracked {$this->times($times)} and none matched.",
        };

        PHPUnit::assertTrue(
            $this->tracked($trackable, $callback)->isNotEmpty(),
            $message.$this->droppedNote($trackable),
        );
    }

    public function assertTrackedTimes(string $trackable, int $times = 1): void
    {
        $count = $this->tracked($trackable)->count();

        PHPUnit::assertSame(
            $times,
            $count,
            "Expected [{$trackable}] to be tracked {$this->times($times)}, but it was tracked {$this->times($count)}.".$this->droppedNote($trackable),
        );
    }

    /**
     * @param  (callable(TrackedEvent): bool)|null  $callback
     */
    public function assertNotTracked(string $trackable, ?callable $callback = null): void
    {
        $count = $this->tracked($trackable, $callback)->count();

        PHPUnit::assertSame(
            0,
            $count,
            $callback === null
                ? "Expected [{$trackable}] not to be tracked, but it was tracked {$this->times($count)}."
                : "Expected [{$trackable}] not to be tracked matching the callback, but it matched {$this->times($count)}.",
        );
    }

    public function assertNothingTracked(): void
    {
        $tracked = collect($this->events)
            ->countBy(fn (TrackedEvent $event) => $event->trackable)
            ->map(fn (int $count, string $trackable) => "- [{$trackable}] {$this->times($count)}")
            ->implode("\n");

        PHPUnit::assertEmpty($this->events, "Expected nothing to be tracked, but these were:\n\n{$tracked}");
    }

    /**
     * Calls to the trackable in the order they were made, adjust() calls included.
     *
     * @param  (callable(TrackedEvent): bool)|null  $callback
     * @return Collection<int, TrackedEvent>
     */
    public function tracked(string $trackable, ?callable $callback = null): Collection
    {
        return collect($this->events)
            ->filter(fn (TrackedEvent $event) => $event->trackable === $trackable && ($callback === null || $callback($event)))
            ->values();
    }

    /**
     * @param  int|list<int>|null  $count
     * @param  int|float|string|list<int|float|string>|null  $amount
     * @param  int|list<int>|null  $cents
     */
    private function record(
        bool $adjustment,
        string $trackable,
        mixed $identifiable,
        int|array|null $count,
        int|float|string|array|null $amount,
        ?DateTimeInterface $occurredAt,
        ?string $note,
        ?bool $activity,
        ?string $id,
        int|array|null $cents,
    ): void {
        try {
            EventRules::check($id, $count, $amount, $cents);

            // Only to drop what real delivery drops: TrackedEvent keeps the amount as the call passed it.
            if ($cents === null) {
                Amounts::encode($amount);
            } else {
                Amounts::fromCents($cents);
            }

            $identifiables = Identifiables::normalize($identifiable);

            if (! EventRules::validUtf8([$trackable, $id, $note, $identifiables, $amount])) {
                Log::warning(EventRules::INVALID_UTF8, [
                    'trackable' => mb_check_encoding($trackable, 'UTF-8') ? $trackable : null,
                ]);
                $this->dropped[$trackable][] = EventRules::INVALID_UTF8;

                return;
            }

            $size = Batch::size(['count' => $count, 'amount' => $amount, 'cents' => $cents]);

            if ($size > Batch::MAX_EVENTS) {
                $reason = "Bump dropped an event with {$size} values: a request takes at most ".Batch::MAX_EVENTS.'.';

                Log::warning($reason, ['trackable' => $trackable]);
                $this->dropped[$trackable][] = $reason;

                return;
            }

            $this->events[] = new TrackedEvent(
                trackable: $trackable,
                identifiables: match (true) {
                    $identifiables === null => [],
                    is_array($identifiables) && array_is_list($identifiables) => $identifiables,
                    default => [$identifiables],
                },
                count: $count,
                amount: $amount,
                occurredAt: $occurredAt === null ? Carbon::now()->toImmutable() : CarbonImmutable::instance($occurredAt),
                note: $note,
                activity: $activity,
                adjustment: $adjustment,
                id: $id,
                cents: $cents,
            );
        } catch (Throwable $e) {
            Log::warning('Bump dropped an event it could not record.', [
                'trackable' => $trackable,
                'exception' => $e->getMessage(),
            ]);

            $this->dropped[$trackable][] = $e->getMessage();
        }
    }

    /**
     * Two decimals, like the API answers. A float has to be exactly the float of a two-decimal number:
     * 59.9 becomes "59.90", while 0.1 + 0.2 throws instead of being rounded.
     */
    private static function amount(int|float|string $amount): string
    {
        $text = is_float($amount) ? sprintf('%.2F', $amount) : (string) $amount;

        if ((is_float($amount) && (float) $text !== $amount) || preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $text, $parts) !== 1) {
            throw new InvalidArgumentException('withTotal() takes an amount with at most 2 decimals, got '.var_export($amount, true).'.');
        }

        $units = ltrim($parts[2], '0') ?: '0';
        $cents = str_pad($parts[3] ?? '', 2, '0');
        $sign = $parts[1] === '-' && ($units !== '0' || $cents !== '00') ? '-' : '';

        return "{$sign}{$units}.{$cents}";
    }

    private function droppedNote(string $trackable): string
    {
        $reasons = $this->dropped[$trackable] ?? [];

        if ($reasons === []) {
            return '';
        }

        return sprintf(
            "\n\nBump dropped %d %s to [%s]:\n- %s",
            count($reasons),
            Str::plural('call', count($reasons)),
            $trackable,
            implode("\n- ", array_unique($reasons)),
        );
    }

    private function droppedForgetsNote(): string
    {
        if ($this->droppedForgets === []) {
            return '';
        }

        return sprintf(
            "\n\nBump dropped %d %s to forget():\n- %s",
            count($this->droppedForgets),
            Str::plural('call', count($this->droppedForgets)),
            implode("\n- ", array_unique($this->droppedForgets)),
        );
    }

    private function handle(Model|string|int $identifiable): string
    {
        return $identifiable instanceof Model ? Identifiables::handle($identifiable) : (string) $identifiable;
    }

    private function times(int $count): string
    {
        return $count.' '.Str::plural('time', $count);
    }
}
