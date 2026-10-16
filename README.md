# Bump for Laravel

Laravel client for [Bump](https://bumpbunny.dev). Send events from your app, and Bump keeps exact running totals per trackable, per identifiable and project-wide.

```php
Bump::track('orders', identifiable: $order->user, amount: 49.99);
```

Requires PHP 8.3+ and Laravel 12 or 13.

## Install

The package lives in a private repository. Add it to the app's `composer.json`:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "git@bitbucket.org:squaredlemon/bump-laravel.git"
    }
]
```

Then require it:

```bash
composer require stickysoftware/bump-laravel
```

Composer reads the repository over SSH, so the machine running `composer install` (your laptop, CI, the server's deploy user) needs an SSH key with read access to the repository.

The service provider and the `Bump` facade register themselves. To change config beyond the env vars below, publish it:

```bash
php artisan vendor:publish --tag=bump-config
```

## Environment

| Variable | Default | |
|---|---|---|
| `BUMP_LIVE_TOKEN` | | Used when `APP_ENV=production` |
| `BUMP_TEST_TOKEN` | | Used in every other environment |
| `BUMP_URL` | `https://api.bumpbunny.dev/v1` | |
| `BUMP_DELIVERY` | `queue` | `queue` or `sync` |
| `BUMP_SYNC_TIMEOUT` | `5` | seconds, `sync` only |
| `BUMP_HANDLES` | `prefixed_id` | `prefixed_id` or `key`, see [Identifiables](#identifiables) |
| `BUMP_QUEUE_CONNECTION` | app default | |
| `BUMP_QUEUE` | app default | |
| `BUMP_ENABLED` | `true` | `false` drops every call |

Create both tokens in the Bump dashboard, under the project's settings in live and in test. Production gets `BUMP_LIVE_TOKEN`, local and staging get `BUMP_TEST_TOKEN`.

Without the token for the current environment, the package is off: calls are accepted and dropped, with one `debug` log line per process.

`php artisan about` has a Bump section with these settings, and which token the environment uses and whether it's set. Never the token itself.

## Usage

```php
use StickySoftware\Bump\Facades\Bump;

Bump::track('orders', identifiable: $order->user, amount: $order->total);
Bump::track('members', identifiable: $team);
Bump::track('members', identifiable: $team, count: -1);

Bump::adjust('orders', identifiable: $order->user, amount: -25.00, note: 'partial refund');
```

Every argument is optional except the trackable, and anything you leave out is left out of the request, so Bump's own defaults apply (`count` 1, `amount` 0, `occurred_at` now).

```php
Bump::track(
    'orders',
    identifiable: $user,                // a model, a handle, or a list of them
    count: 1,                           // or a list: [1, 2] sends two events
    amount: 49.99,                      // or a list
    occurredAt: $order->paid_at,
    note: 'first order',
    activity: true,
);
```

`adjust()` is for corrections. It takes the same arguments but sends `count: 0` and `activity: false` unless you pass them, so a refund changes the amount without counting another order or an active user.

### Identifiables

Pass a string handle, a model, or a list mixing both. A model becomes the first of these that it has:

1. Its `bumpHandle()`, below.
2. Its prefixed ID from [spatie/laravel-prefixed-ids](https://github.com/spatie/laravel-prefixed-ids): `usr_3f9a…`. Only with `BUMP_HANDLES=prefixed_id`, the default. The attribute name comes from that package's config. A model loaded without that column, `select('id')` for example, fetches it with one query. A row whose prefixed ID is empty gets the `user_12` handle below, with one warning per model class.
3. Its morph map alias, or its snake_case class name, plus its key: `user_12`, `team_member_3`.

`BUMP_HANDLES=key` skips the prefixed ID. Pick the setting before the first live event and leave it: changing it gives every model a new handle, so totals restart under new identifiables.

A handle takes at most 64 characters. When a model's handle is longer, its event is dropped with a warning.

Define these on a model to change what is sent:

```php
public function bumpHandle(): string
{
    return 'shop_'.$this->slug;
}

public function bumpLabel(): ?string
{
    return $this->name;
}
```

Labels are opt-in: without `bumpLabel()` no label is sent. Never send email addresses. Company and team names are fine as labels; person names are your call.

### Activity

Bump counts an event as activity (DAU, WAU, MAU) by the trackable's setting. Web requests send no `activity` field, so the setting decides. Queued jobs and console commands send `activity: false`, since there the system acted, not a person. Pass `activity` to override either.

### When events are sent

Calls go into a buffer that is sent once per web request or console command (when it terminates) and once per queued job (when it's processed or fails), or earlier when it holds 100 events. Each event gets its id and `occurred_at` when you call `track()`, not when the buffer is sent.

With `BUMP_DELIVERY=queue` the buffer goes out as a queued job, so the app needs a running worker. Use `sync` for apps without one: the HTTP request then happens while the request or command terminates, limited to `BUMP_SYNC_TIMEOUT` seconds. An answer that takes at least half that logs a warning with the measured time (`Bump took 3.4s to answer (sync timeout 5s).`). If those lines show up, raise the timeout or switch to `queue`.

### Failures

The package never throws into your app. It logs instead:

- `error` when Bump refuses a batch (400, 401, 403) with the API's error code and message, when a batch can't be queued, and when retries run out
- `warning` for each event Bump rejects, with the field and the reason
- `warning` when a job attempt fails and goes back on the queue: its events are dropped, because the next attempt records them again

Connection errors, 5xx and 429 are retried, waiting for `Retry-After` when Bump sends one. Queued batches retry 5 times over about half an hour; sync delivery retries twice and waits at most 10 seconds at a time. Every event carries an id, so a retried batch never counts twice.

## Backfill

Each kind of history gets a class:

```bash
php artisan make:bump-backfill OrdersBackfill
```

That writes `app/Bump/OrdersBackfill.php`. It reads `App\Models\Model` by `created_at` and sends `orders` events with ids like `orders-1042`. Put in your model and the column the event happened at, and add what else the event carries:

```php
namespace App\Bump;

use App\Models\Order;
use StickySoftware\Bump\Backfill\BackfillWindow;
use StickySoftware\Bump\Contracts\Backfill;

class OrdersBackfill implements Backfill
{
    public function events(BackfillWindow $window): iterable
    {
        $rows = $window->constrain(Order::with('user'), 'paid_at')->lazyById();

        foreach ($rows as $row) {
            yield [
                'id' => "orders-{$row->id}",
                'trackable' => 'orders',
                'identifiable' => $row->user,
                'amount' => $row->total,
                'occurred_at' => $row->paid_at,
            ];
        }
    }
}
```

Events are in the API's item shape. To change what the command generates, publish its stub with `php artisan vendor:publish --tag=bump-stubs` and edit `stubs/bump-backfill.stub`.

Live events carry ids the package makes up, so Bump can't match them to the same events sent again with ids from your data. A backfill that sends anything from after live counting started counts it twice.

So every backfill runs up to `--until`: the moment live counting started. Bump shows it as Tracking since, in Developers > Events of the project's live environment. Copy it there and pass it as `--until`. The copied value carries its offset (`2026-10-16T14:02:11+02:00`), so it means the same moment in any app timezone.

`constrain()` keeps rows with `paid_at` before `until`, and from `since` on when there is one. `$window->until` and `$window->since` are there for queries it doesn't fit. Both are in the app timezone: Laravel puts a date into a query as its clock time, without converting it.

```bash
php artisan bump:backfill "App\Bump\OrdersBackfill" "App\Bump\RefundsBackfill" --until="2026-10-16T14:02:11+02:00"
```

`--until` and `--since` are read in the app timezone unless they name one. The command shows the window in the app timezone and in UTC and asks before sending; `--force` skips the question. Classes run in the order given. When Bump doesn't take a batch, the command stops at that class, and running the same command again carries on.

Every event needs an `id` built from your own data (max 64 characters), so running the backfill again skips what was already sent. It also needs an `occurred_at`: Bump would date an event without one now, after the window. An event missing either is rejected. So is an event whose `occurred_at` falls outside the window, so a class that forgets `constrain()` still can't send the overlap. Events go out in batches of 100, marked as backfill, so milestones they reach never fire integrations. Each class ends with how many events were accepted, duplicate and rejected, and why.

To send history in slices, start the later ones with `--since`. `since` is included and `until` isn't, so slices that share a boundary never overlap or leave a gap:

```bash
php artisan bump:backfill "App\Bump\OrdersBackfill" --until="2025-01-01"
php artisan bump:backfill "App\Bump\OrdersBackfill" --since="2025-01-01" --until="2026-10-16T14:02:11+02:00"
```

`--dry-run` counts what each class would send, and what it would reject and why, without sending anything. It needs no token.

## Testing your app

`Bump::fake()` records calls and sends nothing, so tests never reach the API:

```php
use StickySoftware\Bump\Facades\Bump;
use StickySoftware\Bump\Testing\TrackedEvent;

test('paying an order tracks it', function () {
    Bump::fake();
    $order = Order::factory()->create();

    $this->post("/orders/{$order->id}/pay");

    Bump::assertTracked('orders', fn (TrackedEvent $event) => $event->hasIdentifiable($order->user));
    Bump::assertNotTracked('refunds');
});
```

Also there: `assertTrackedTimes('orders', 2)`, `assertNothingTracked()`, and `tracked('orders')` for a collection of calls. The fake records with or without a token.

The callback gets a `TrackedEvent` per call: `trackable`, `identifiables`, `count`, `amount`, `occurredAt`, `note`, `activity` and `adjustment`. Models are resolved to handles like in real delivery, and a call real delivery would drop, an unsaved model for example, is dropped here too: the failing assertion says why. `adjust()` calls count as tracked, with `adjustment` true and their defaults filled in. A field the call leaves out is `null`.

## Known limits

- Mass updates and deletes (`Order::where(...)->update(...)`) skip Eloquent model events, so tracking done in observers or model events won't happen for them. Call `Bump::track()` or `Bump::adjust()` next to those queries.
- A single call whose `count` or `amount` list holds more than 100 values is dropped with a warning: the API takes at most 100 events per request.

## AI agents

The package ships [Laravel Boost](https://github.com/laravel/boost) guidelines in `resources/boost/guidelines/core.blade.php`. Run `php artisan boost:update` after installing and pick `stickysoftware/bump-laravel` when asked, and your app's agents learn how to use it.

## Changelog

See [CHANGELOG.md](CHANGELOG.md). Coming from an earlier version? Read [UPGRADING.md](UPGRADING.md) before you update.

## Development

```bash
composer test       # Pest
composer lint       # Pint
composer analyse    # PHPStan (Larastan)
```

The end-to-end tests send real requests and are skipped by default:

```bash
BUMP_E2E_TOKEN=bump_test_… vendor/bin/pest --group=end-to-end
```

`BUMP_E2E_URL` defaults to the local app at `https://api.bump.test/v1`.
