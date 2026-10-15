<?php

namespace StickySoftware\Bump;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Holds no state of its own: the buffer is resolved on every call, so the instance the facade caches
 * stays correct across Octane requests and queued jobs.
 */
final class Bump
{
    private static bool $reportedMissingToken = false;

    /**
     * @param  int|list<int>|null  $count
     * @param  int|float|string|list<int|float|string>|null  $amount
     */
    public function track(
        string $trackable,
        mixed $identifiable = null,
        int|array|null $count = null,
        int|float|string|array|null $amount = null,
        ?DateTimeInterface $occurredAt = null,
        ?string $note = null,
        ?bool $activity = null,
    ): void {
        $this->record($trackable, $identifiable, $count, $amount, $occurredAt, $note, $activity ?? $this->defaultActivity());
    }

    /**
     * A correction: counts nothing and isn't activity unless the call says so.
     *
     * @param  int|list<int>|null  $count
     * @param  int|float|string|list<int|float|string>|null  $amount
     */
    public function adjust(
        string $trackable,
        mixed $identifiable = null,
        int|array|null $count = 0,
        int|float|string|array|null $amount = null,
        ?DateTimeInterface $occurredAt = null,
        ?string $note = null,
        ?bool $activity = false,
    ): void {
        $this->record($trackable, $identifiable, $count, $amount, $occurredAt, $note, $activity);
    }

    /**
     * Sends everything recorded so far. Runs by itself at the end of every request, command and job.
     */
    public function flush(): void
    {
        try {
            $events = app(Buffer::class)->pull();

            if ($events !== []) {
                app(Delivery::class)->deliver($events);
            }
        } catch (Throwable $e) {
            Log::error('Bump could not send its events.', ['exception' => $e->getMessage()]);
        }
    }

    /**
     * @param  int|list<int>|null  $count
     * @param  int|float|string|list<int|float|string>|null  $amount
     */
    private function record(
        string $trackable,
        mixed $identifiable,
        int|array|null $count,
        int|float|string|array|null $amount,
        ?DateTimeInterface $occurredAt,
        ?string $note,
        ?bool $activity,
    ): void {
        try {
            if (! $this->enabled()) {
                return;
            }

            $event = array_filter([
                'id' => (string) Str::uuid(),
                'trackable' => $trackable,
                'identifiable' => Identifiables::normalize($identifiable),
                'count' => $count,
                'amount' => $amount,
                'occurred_at' => ($occurredAt ?? Carbon::now())->format(DateTimeInterface::ATOM),
                'activity' => $activity,
                'note' => $note,
            ], fn (mixed $value) => $value !== null);

            $this->buffer($event);
        } catch (Throwable $e) {
            Log::warning('Bump dropped an event it could not record.', [
                'trackable' => $trackable,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function buffer(array $event): void
    {
        $buffer = app(Buffer::class);
        $size = Batch::size($event);

        // The API refuses the whole request above the limit, which would take the other events down with it.
        if ($size > Batch::MAX_EVENTS) {
            Log::warning("Bump dropped an event with {$size} values: a request takes at most ".Batch::MAX_EVENTS.'.', [
                'trackable' => $event['trackable'],
            ]);

            return;
        }

        if ($buffer->size() + $size > Batch::MAX_EVENTS) {
            $this->flush();
        }

        $buffer->push($event);

        if ($buffer->size() >= Batch::MAX_EVENTS) {
            $this->flush();
        }
    }

    private function enabled(): bool
    {
        if (! config('bump.enabled')) {
            return false;
        }

        $api = app(Api::class);

        if ($api->token() !== null) {
            return true;
        }

        if (! self::$reportedMissingToken) {
            self::$reportedMissingToken = true;

            Log::debug('Bump is off: '.$api->tokenVariable().' is not set.');
        }

        return false;
    }

    private function defaultActivity(): ?bool
    {
        return app(Context::class)->isWebRequest() ? null : false;
    }

    /**
     * The missing-token log line is once per process; tests need it back.
     *
     * @internal
     */
    public static function forgetReportedMissingToken(): void
    {
        self::$reportedMissingToken = false;
    }
}
