<?php

namespace StickySoftware\Bump\Console;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;
use StickySoftware\Bump\Amounts;
use StickySoftware\Bump\Api;
use StickySoftware\Bump\Backfill\BackfillWindow;
use StickySoftware\Bump\Batch;
use StickySoftware\Bump\Contracts\Backfill;
use StickySoftware\Bump\Delivery;
use StickySoftware\Bump\EventRules;
use StickySoftware\Bump\Identifiables;
use StickySoftware\Bump\SendOutcome;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * @internal
 */
#[AsCommand(name: 'bump:backfill')]
class BackfillCommand extends Command
{
    protected $signature = 'bump:backfill
                            {class* : One or more classes that implement '.Backfill::class.'}
                            {--until= : When live counting started, in the app timezone}
                            {--since= : Where to start, in the app timezone}
                            {--force : Send without asking}
                            {--dry-run : Count the events without sending them}';

    protected $description = 'Send past events to Bump, marked as backfill';

    /** @var array{accepted: int, duplicate: int, rejected: int} */
    private array $counts;

    /** @var array<string, int> message => how often */
    private array $rejections;

    public function handle(Api $api, Delivery $delivery): int
    {
        $classes = [];

        foreach ((array) $this->argument('class') as $class) {
            if (! is_string($class) || ! is_subclass_of($class, Backfill::class)) {
                $this->components->error(sprintf("%s doesn't implement %s.", is_string($class) ? $class : 'The class', Backfill::class));

                return self::FAILURE;
            }

            $classes[] = $class;
        }

        $window = $this->window();

        if ($window === null) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! config('bump.enabled')) {
            $this->components->error('Bump is turned off (BUMP_ENABLED).');

            return self::FAILURE;
        }

        if (! $dryRun && $api->token() === null) {
            $this->components->error($api->tokenVariable().' is not set.');

            return self::FAILURE;
        }

        $refusal = $dryRun ? null : $api->urlRefusal();

        if ($refusal !== null) {
            $this->components->error($refusal);

            return self::FAILURE;
        }

        $this->describe($window);

        if ($dryRun) {
            $this->countEvents($classes, $window);

            return self::SUCCESS;
        }

        $question = sprintf(
            'Send everything from %s until %s to Bump?',
            $window->since?->format('Y-m-d H:i:s T') ?? 'the start',
            $window->until->format('Y-m-d H:i:s T'),
        );

        if (! $this->option('force') && ! $this->confirm($question)) {
            $this->components->info('Nothing sent.');

            return self::FAILURE;
        }

        foreach ($classes as $class) {
            if (! $this->backfill($class, $window, $delivery)) {
                $this->components->error("Stopped at {$class}. Run the same command again to carry on.");

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    private function window(): ?BackfillWindow
    {
        $until = $this->timeOption('until');

        if ($until === null) {
            $this->components->error('--until is required: when live counting started. Events after it were already counted live, so Bump would count them twice.');

            return null;
        }

        if ($until === false) {
            return null;
        }

        if ($until->isFuture()) {
            $this->components->error('--until is in the future. Live counting has already started, so the cutoff is behind us.');

            return null;
        }

        $since = $this->timeOption('since');

        if ($since === false) {
            return null;
        }

        if ($since !== null && $since->greaterThanOrEqualTo($until)) {
            $this->components->error('--since has to be before --until.');

            return null;
        }

        return new BackfillWindow($until, $since);
    }

    /**
     * @return CarbonImmutable|false|null the time, false when it can't be read (and said so), null when not given
     */
    private function timeOption(string $name): CarbonImmutable|false|null
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, (string) config('app.timezone'));
        } catch (InvalidFormatException) {
            $this->components->error("Can't read --{$name}=\"{$value}\" as a time.");

            return false;
        }
    }

    private function describe(BackfillWindow $window): void
    {
        $zones = array_unique([(string) config('app.timezone'), 'UTC']);

        foreach (['Since' => $window->since, 'Until' => $window->until] as $label => $time) {
            if ($time === null) {
                $this->components->twoColumnDetail($label, 'the start');

                continue;
            }

            foreach ($zones as $zone) {
                $this->components->twoColumnDetail("{$label} ({$zone})", $time->setTimezone($zone)->format('Y-m-d H:i:s'));
            }
        }

        $this->newLine();
    }

    /**
     * @param  list<class-string<Backfill>>  $classes
     */
    private function countEvents(array $classes, BackfillWindow $window): void
    {
        $total = 0;

        foreach ($classes as $class) {
            $this->rejections = [];
            $count = 0;

            foreach ($this->events($class, $window) as $event) {
                $event = $this->normalize($event, $window);

                if (is_string($event)) {
                    $this->rejections[$event] = ($this->rejections[$event] ?? 0) + 1;

                    continue;
                }

                $count += Batch::size($event);
            }

            $total += $count;

            $this->components->twoColumnDetail($class, $this->eventCount($count));
            $this->showRejections();
        }

        $this->components->twoColumnDetail('<options=bold>Total</>', $this->eventCount($total));
    }

    /**
     * @param  class-string<Backfill>  $class
     * @return bool false when Bump didn't take a batch
     */
    private function backfill(string $class, BackfillWindow $window, Delivery $delivery): bool
    {
        // The command instance outlives one run when Artisan::call() runs it twice in one process.
        $this->counts = ['accepted' => 0, 'duplicate' => 0, 'rejected' => 0];
        $this->rejections = [];

        $this->line("<options=bold>{$class}</>");

        $events = $this->events($class, $window);

        $bar = $this->output->createProgressBar(is_countable($events) ? count($events) : 0);
        $batch = [];
        $batchSize = 0;
        $stopped = null;

        foreach ($events as $event) {
            $bar->advance();

            $event = $this->normalize($event, $window);

            if (is_string($event)) {
                $this->reject($event);

                continue;
            }

            $size = Batch::size($event);

            if ($batchSize + $size > Batch::MAX_EVENTS) {
                $stopped = $this->send($delivery, $batch);

                if ($stopped !== null) {
                    break;
                }

                [$batch, $batchSize] = [[], 0];
            }

            $batch[] = $event;
            $batchSize += $size;
        }

        if ($stopped === null && $batch !== []) {
            $stopped = $this->send($delivery, $batch);
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(['Accepted', 'Duplicate', 'Rejected'], [array_values($this->counts)]);
        $this->showRejections();

        if ($stopped !== null) {
            $this->components->error($stopped);

            return false;
        }

        $this->newLine();

        return true;
    }

    /**
     * @param  class-string<Backfill>  $class
     * @return iterable<mixed>
     */
    private function events(string $class, BackfillWindow $window): iterable
    {
        /** @var Backfill $backfill */
        $backfill = $this->laravel->make($class);

        return $backfill->events($window);
    }

    /**
     * @return array<string, mixed>|string the event, or why it can't be sent
     */
    private function normalize(mixed $event, BackfillWindow $window): array|string
    {
        if (! is_array($event)) {
            return 'Backfill events are arrays.';
        }

        $id = $event['id'] ?? null;

        if (! (is_string($id) && $id !== '') && ! is_int($id)) {
            return 'Backfill events need an id derived from your own data.';
        }

        if (Batch::size($event) > Batch::MAX_EVENTS) {
            return 'A count or amount list takes at most '.Batch::MAX_EVENTS.' values.';
        }

        $event['id'] = (string) $id;

        try {
            $event = $this->amount($event);
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }

        if (array_key_exists('identifiable', $event)) {
            try {
                $event['identifiable'] = Identifiables::normalize($event['identifiable']);
            } catch (InvalidArgumentException $e) {
                return $e->getMessage();
            }
        }

        $occurredAt = $event['occurred_at'] ?? null;

        // Bump dates an event without one "now", which is after `until` and so outside every window.
        if ($occurredAt === null || (is_string($occurredAt) && trim($occurredAt) === '')) {
            return 'occurred_at is required for a backfill.';
        }

        // Catches a class that forgot constrain(): events after `until` were already counted live.
        $occurredAt = $this->time($occurredAt);

        if ($occurredAt !== null && ($occurredAt >= $window->until || ($window->since !== null && $occurredAt < $window->since))) {
            return 'occurred_at outside the window.';
        }

        if (($event['occurred_at'] ?? null) instanceof DateTimeInterface) {
            $event['occurred_at'] = $event['occurred_at']->format(DateTimeInterface::ATOM);
        }

        if (! EventRules::validUtf8($event)) {
            return "The event has text that isn't valid UTF-8.";
        }

        return $event;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException with the reason the event can't be sent
     */
    private function amount(array $event): array
    {
        $cents = $event['cents'] ?? null;
        unset($event['cents']);

        EventRules::checkCents($event['count'] ?? null, $event['amount'] ?? null, $cents);

        if ($cents !== null) {
            $event['amount'] = Amounts::fromCents($cents);
        } elseif (array_key_exists('amount', $event)) {
            $event['amount'] = Amounts::encode($event['amount']);
        }

        return $event;
    }

    /**
     * A string Carbon can't read is left for the API, which rejects it with the format it expects.
     */
    private function time(mixed $value): ?DateTimeInterface
    {
        if ($value instanceof DateTimeInterface) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (InvalidFormatException) {
            return null;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $batch
     * @return string|null why the backfill has to stop
     */
    private function send(Delivery $delivery, array $batch): ?string
    {
        try {
            $result = $delivery->sendNow($batch, backfill: true, attempts: 5, maxWait: 120);
        } catch (Throwable $e) {
            return "Bump couldn't send a batch: ".explode("\n", $e->getMessage())[0];
        }

        if ($result->outcome !== SendOutcome::Delivered) {
            return "Bump didn't take a batch ({$result->reason}).";
        }

        foreach ($result->counts() as $status => $count) {
            $this->counts[$status] += $count;
        }

        foreach ($result->results as $item) {
            if (($item['status'] ?? null) === 'rejected') {
                $message = $item['error']['message'] ?? 'Rejected.';
                $this->rejections[$message] = ($this->rejections[$message] ?? 0) + 1;
            }
        }

        return null;
    }

    private function reject(string $message): void
    {
        $this->counts['rejected']++;
        $this->rejections[$message] = ($this->rejections[$message] ?? 0) + 1;
    }

    private function eventCount(int $count): string
    {
        return number_format($count).' '.Str::plural('event', $count);
    }

    private function showRejections(): void
    {
        foreach ($this->rejections as $message => $times) {
            $this->line("  {$times} × {$message}");
        }
    }
}
