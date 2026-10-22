<?php

namespace StickySoftware\Bump;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use StickySoftware\Bump\Exceptions\BumpException;
use Throwable;

/**
 * Keep this stateless: the facade caches one instance across Octane requests and queued jobs.
 */
final class Tracker
{
    private static bool $reportedMissingToken = false;

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
        $this->record($trackable, $identifiable, $count, $amount, $occurredAt, $note, $activity ?? $this->defaultActivity(), $id, $cents);
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
        $this->record($trackable, $identifiable, $count, $amount, $occurredAt, $note, $activity, $id, $cents);
    }

    /**
     * Runs by itself when a request, command or job ends. Call it in long-running loops.
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
     * Never log the handle: it's what is being erased.
     */
    public function forget(Model|string|int $identifiable): void
    {
        try {
            if (! $this->enabled()) {
                return;
            }

            $handle = Identifiables::handleToForget($identifiable);

            app(Delivery::class)->forget(app(Buffer::class)->pull(), $handle);
        } catch (Throwable $e) {
            Log::warning('Bump could not forget an identifiable.', ['exception' => $e->getMessage()]);
        }
    }

    /**
     * Unlike sending, a read throws: there is no number to return without an answer.
     *
     * @throws InvalidArgumentException for an empty handle or one that isn't valid UTF-8
     * @throws BumpException when the read fails
     */
    public function total(string $trackable): Total
    {
        EventRules::checkTrackable($trackable);

        if (! config('bump.enabled')) {
            throw new BumpException("Bump is turned off (BUMP_ENABLED), so it can't read totals.");
        }

        return app(Api::class)->total($trackable, (int) config('bump.sync_timeout'));
    }

    /**
     * @param  int|list<int>|null  $count
     * @param  int|float|string|list<int|float|string>|null  $amount
     * @param  int|list<int>|null  $cents
     */
    private function record(
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
            if (! $this->enabled()) {
                return;
            }

            EventRules::check($id, $count, $amount, $cents);

            $amount = $cents === null ? Amounts::encode($amount) : Amounts::fromCents($cents);

            // A count or amount list keeps the id as given: the API derives "{id}:0", "{id}:1" per value.
            $event = array_filter([
                'id' => $id ?? (string) Str::uuid(),
                'trackable' => $trackable,
                'identifiable' => Identifiables::normalize($identifiable),
                'count' => $count,
                'amount' => $amount,
                'occurred_at' => ($occurredAt ?? Carbon::now())->format(DateTimeInterface::ATOM),
                'activity' => $activity,
                'note' => $note,
            ], fn (mixed $value) => $value !== null);

            if (! EventRules::validUtf8($event)) {
                Log::warning(EventRules::INVALID_UTF8, [
                    'trackable' => mb_check_encoding($trackable, 'UTF-8') ? $trackable : null,
                ]);

                return;
            }

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

            $message = 'Bump is off: '.$api->tokenVariable().' is not set.';

            app()->isProduction() ? Log::warning($message) : Log::debug($message);
        }

        return false;
    }

    private function defaultActivity(): ?bool
    {
        return app(Context::class)->isWebRequest() ? null : false;
    }

    /**
     * Tests reset the once-per-process log line.
     *
     * @internal
     */
    public static function forgetReportedMissingToken(): void
    {
        self::$reportedMissingToken = false;
    }
}
