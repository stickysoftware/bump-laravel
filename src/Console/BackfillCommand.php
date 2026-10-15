<?php

namespace StickySoftware\Bump\Console;

use DateTimeInterface;
use Illuminate\Console\Command;
use InvalidArgumentException;
use StickySoftware\Bump\Api;
use StickySoftware\Bump\Batch;
use StickySoftware\Bump\Contracts\Backfill;
use StickySoftware\Bump\Delivery;
use StickySoftware\Bump\Identifiables;
use StickySoftware\Bump\SendOutcome;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'bump:backfill')]
class BackfillCommand extends Command
{
    protected $signature = 'bump:backfill {class : A class that implements '.Backfill::class.'}';

    protected $description = 'Send past events to Bump, marked as backfill';

    /** @var array{accepted: int, duplicate: int, rejected: int} */
    private array $counts;

    /** @var array<string, int> message => how often */
    private array $rejections;

    public function handle(Api $api, Delivery $delivery): int
    {
        // The command instance outlives one run when Artisan::call() runs it twice in one process.
        $this->counts = ['accepted' => 0, 'duplicate' => 0, 'rejected' => 0];
        $this->rejections = [];

        $class = $this->argument('class');

        if (! is_string($class) || ! is_subclass_of($class, Backfill::class)) {
            $this->components->error(sprintf("%s doesn't implement %s.", is_string($class) ? $class : 'The class', Backfill::class));

            return self::FAILURE;
        }

        if (! config('bump.enabled')) {
            $this->components->error('Bump is turned off (BUMP_ENABLED).');

            return self::FAILURE;
        }

        if ($api->token() === null) {
            $this->components->error($api->tokenVariable().' is not set.');

            return self::FAILURE;
        }

        /** @var Backfill $backfill */
        $backfill = $this->laravel->make($class);
        $events = $backfill->events();

        $bar = $this->output->createProgressBar(is_countable($events) ? count($events) : 0);
        $batch = [];
        $batchSize = 0;
        $stopped = null;

        foreach ($events as $event) {
            $bar->advance();

            $event = $this->normalize($event);

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

        foreach ($this->rejections as $message => $times) {
            $this->line("  {$times} × {$message}");
        }

        if ($stopped !== null) {
            $this->components->error("Stopped: {$stopped} Events sent so far are kept, and running it again skips them.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>|string the event, or why it can't be sent
     */
    private function normalize(mixed $event): array|string
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

        if (array_key_exists('identifiable', $event)) {
            try {
                $event['identifiable'] = Identifiables::normalize($event['identifiable']);
            } catch (InvalidArgumentException $e) {
                return $e->getMessage();
            }
        }

        if (($event['occurred_at'] ?? null) instanceof DateTimeInterface) {
            $event['occurred_at'] = $event['occurred_at']->format(DateTimeInterface::ATOM);
        }

        return $event;
    }

    /**
     * @param  list<array<string, mixed>>  $batch
     * @return string|null why the backfill has to stop
     */
    private function send(Delivery $delivery, array $batch): ?string
    {
        // A backfill can wait out the whole rate-limit window: nobody is waiting on a page.
        $result = $delivery->sendNow($batch, backfill: true, attempts: 5, maxWait: 120);

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
}
