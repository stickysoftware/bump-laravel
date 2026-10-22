# Bump for Laravel

Laravel client for [Bump](https://bumpbunny.dev). Send events from your app, and Bump keeps exact running totals per trackable, per identifiable and project-wide.

```php
Bump::track('orders', identifiable: $order->user, amount: 49.99);
```

Requires PHP 8.3+ and Laravel 12.45+ or 13. The API itself is documented at [bumpbunny.dev/docs](https://bumpbunny.dev/docs).

## Install

```bash
composer require stickysoftware/bump-laravel
```

Add the token for each environment to its `.env`:

```dotenv
BUMP_LIVE_TOKEN=bump_live_…   # production
BUMP_TEST_TOKEN=bump_test_…   # local, staging, CI
```

Events go out from a queued job by default, so the app needs a running queue worker. Without one, set `BUMP_DELIVERY=sync`, see [When events are sent](#when-events-are-sent).

`BUMP_URL` has to use https. Plain http works only for localhost, 127.0.0.1, ::1 and hosts ending in `.test` or `.localhost`; with any other plain http URL the package sends nothing and logs one error per process.

The service provider and the `Bump` facade register themselves. To change config beyond the env vars below, publish it:

```bash
php artisan vendor:publish --tag=bump-config
```

### Tokens

Create tokens in Bump under **Developers > API tokens**, once with the sidebar on live and once on test. Production gets `BUMP_LIVE_TOKEN`. Every other environment gets `BUMP_TEST_TOKEN`, so tests and staging never touch live numbers.

The live token is used only when `APP_ENV` is exactly `production`. Without the token for the current environment, the package is off: calls are accepted and dropped, with one log line per process. That line is a `warning` in production and `debug` everywhere else. [Reading totals](#reading-totals) throws instead.

`php artisan about` has a Bump section with the settings below, which token the environment uses and whether it's set. Never the token itself.

## Environment

| Variable | Default | |
|---|---|---|
| `BUMP_LIVE_TOKEN` | | Used when `APP_ENV=production` |
| `BUMP_TEST_TOKEN` | | Used in every other environment |
| `BUMP_URL` | `https://api.bumpbunny.dev` | The host only, the package adds `/v1`. https, or plain http on localhost, 127.0.0.1, ::1, `*.test` and `*.localhost` |
| `BUMP_DELIVERY` | `queue` | `queue` or `sync` |
| `BUMP_SYNC_TIMEOUT` | `5` | Seconds, for `sync` delivery and reading totals |
| `BUMP_HANDLES` | `prefixed_id` | `prefixed_id` or `key`, see [Identifiables](#identifiables) |
| `BUMP_QUEUE_CONNECTION` | app default | |
| `BUMP_QUEUE` | app default | |
| `BUMP_ENABLED` | `true`, `false` under `APP_ENV=testing` | `false` drops every call, and `total()` throws |

## Usage

```php
use StickySoftware\Bump\Facades\Bump;

Bump::track('orders', identifiable: $order->user, amount: $order->total);
Bump::track('members', identifiable: $team);
Bump::track('members', identifiable: $team, count: -1);

Bump::adjust('orders', identifiable: $order->user, amount: -25.00, note: 'partial refund');
```

Every argument is optional except the trackable:

```php
Bump::track(
    'orders',
    identifiable: $user,                // a model, a handle, or a list of them
    count: 1,                           // or a list: [1, 2] sends two events
    amount: 49.99,                      // or a list, or cents: 4999 instead
    occurredAt: $order->paid_at,
    note: 'first order',
    activity: true,
    id: "order-{$order->id}",
);
```

What you leave out is left out of the request, so Bump's own defaults apply: `count` 1, `amount` 0, `activity` by the trackable's setting. Two fields are always sent: an `id` (see [Event ids](#event-ids)) and `occurred_at`, which is the time of the call unless you pass `occurredAt`.

`count` and `amount` can't both be lists in one call. Bump rejects that, so the package drops it with a warning.

`adjust()` is for corrections. It takes the same arguments but sends `count: 0` and `activity: false` unless you pass them, so a refund changes the amount without counting another order or an active user.

### Amounts

Amounts are in the project currency. Pass them one of two ways:

- `amount:` takes a decimal, as a float or a string: `49.99`, `'49.99'`.
- `cents:` takes whole cents, as an int: `4999`. Use it for a Cashier amount or a `*_cents` column.

```php
Bump::track('orders', identifiable: $order->user, cents: $order->total_cents);
```

The package sends every amount as a string with two decimals, so `amount: 50.0` goes out as `"50.00"` and `cents: 4999` as `"49.99"`, without float math. Float noise is fine: `0.1 + 0.2` is `0.30000000000000004` in PHP and goes out as `"0.30"`.

An amount takes at most 2 decimals. Bump never rounds, so the package drops an amount with more, like `49.999`, with a warning. Zeros at the end don't count: `'49.9900'` from a `decimal(10,4)` column is `"49.99"`.

An int in `amount:` is sent as it is, and so is a string without decimals like `'50'`. Bump refuses it, since a whole number could be cents, and the project shows it under Problems. For whole cents use `cents:`. For a whole amount, pass a float or a string with decimals: `amount: 50.0` or `amount: '50.00'`.

`amount` and `cents` can't both be passed in one call, and `count` and `cents` can't both be lists. The package drops such a call with a warning.

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

### Event ids

Every event carries an id, and Bump counts an id once. Without `id:` the package makes up a UUID per call, which keeps the package's own retries safe. Pass your own when the same thing can happen twice in your app, like a webhook that's delivered again or a job that runs twice:

```php
Bump::track('orders', identifiable: $order->user, amount: $order->total, id: "order-{$order->id}");
```

One id per event: a refund needs its own, or Bump takes it for the order again and skips it.

```php
Bump::adjust('orders', identifiable: $order->user, amount: -25.00, id: "refund-{$refund->id}");
```

An id takes 1 to 64 characters. An empty or longer one drops the event with a warning. With a `count`, `amount` or `cents` list, send one id for the call: Bump gives each value its own key from it.

### Forgetting an identifiable

When someone asks you to erase their data, tell Bump to forget them:

```php
Bump::forget($user);          // or a handle: Bump::forget('usr_3f9a')
```

Bump removes the label and replaces the handle with a random placeholder. Totals and milestones stay. `forget()` takes one model or handle, never a list or a label.

The token needs **Can forget identifiables**, a switch you turn on when you create it. Without it, `forget()` logs a warning and nothing is forgotten. An identifiable Bump doesn't know counts as forgotten.

Events this request, command or job already recorded go out before the forget. With `sync` delivery both are sent right away, during the call. Events queued earlier by other processes can still arrive after it and create the identifiable again, starting at zero. So call `forget()` once the app has stopped tracking that person, for example in the account deletion itself.

### Reading totals

`Bump::total()` reads one trackable's totals for the whole project, like the number of orders so far for a line on your homepage.

It needs a token made with **Can read totals**. An existing token can't get it later, so make a new one and put it in the same `BUMP_LIVE_TOKEN` or `BUMP_TEST_TOKEN`. One token sends and reads.

Read on a schedule and store the number:

```php
// routes/console.php
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;
use StickySoftware\Bump\Facades\Bump;

Artisan::command('orders:count', function () {
    Cache::forever('orders.count', Bump::total('orders')->count);
});

Schedule::command('orders:count')->hourly();
```

The page shows the stored number:

```php
$orders = Cache::get('orders.count');
```

A page never waits on Bump this way, and a failed read keeps the last number.

`Cache::remember()` is the shortcut:

```php
$orders = Cache::remember('orders.count', now()->addHour(), fn () => Bump::total('orders')->count);
```

The catch: a failed read caches nothing and throws in the page that asked, and a cleared cache puts Bump back in a page load.

`total()` returns a `StickySoftware\Bump\Total`:

| Field | |
|---|---|
| `handle` | The trackable's handle |
| `count` | The total count, an int |
| `amount` | The total amount in the project currency, as a string with two decimals: `"59.94"`, `"0.00"` for a trackable that only counts |
| `cents` | The same amount in cents, an int: `5994` |
| `currency` | The project's currency, like `EUR` |
| `lastEventAt` | When Bump received the latest event, a `CarbonImmutable` in UTC. `null` before the first one |

Production reads with the live token and every other environment with the test token, so they read live and test totals. A read waits at most `BUMP_SYNC_TIMEOUT` seconds and is never retried.

When a read fails, `total()` throws a `StickySoftware\Bump\Exceptions\BumpException`: Bump is turned off, the token isn't set, `BUMP_URL` isn't https, Bump can't be reached, Bump refuses the read, or the answer isn't a trackable. Its message never contains the token. Three fields tell you more, each `null` when it doesn't apply:

- `status`: the HTTP status
- `errorCode`: the API's error code, like `resource_missing` for a handle Bump doesn't know or `missing_ability` for a token without **Can read totals**
- `retryAfter`: the seconds from a 429's `Retry-After`

An empty handle, or one that isn't valid UTF-8, throws an `InvalidArgumentException` before any request. The API side is in Bump's [Reading totals](https://bumpbunny.dev/docs/reading-totals) docs.

### When events are sent

Calls go into a buffer that is sent once per web request or console command (when it terminates) and once per queued job (when it's processed or fails), or earlier when it holds 100 events. Each event gets its id and `occurred_at` when you call `track()`, not when the buffer is sent.

A long-running loop or daemon never terminates, so call `Bump::flush()` after each unit of work:

```php
while ($message = $consumer->next()) {
    $this->handle($message);

    Bump::flush();
}
```

With `BUMP_DELIVERY=queue` the buffer goes out as a queued job. Queued payloads are encrypted with `APP_KEY`, so when you rotate it, keep the old key in `APP_PREVIOUS_KEYS` until the queue is empty. With `sync` the HTTP request happens while the request or command terminates, limited to `BUMP_SYNC_TIMEOUT` seconds per attempt. With retries, the worst case is about 21 seconds, up to 35 when Bump is rate limiting. An answer that takes at least half the timeout logs a warning with the measured time (`Bump took 3.4s to answer (sync timeout 5s).`). If those lines show up, raise the timeout or switch to `queue`.

Under Octane, use `queue`: sync delivery holds the worker until Bump answers.

### Failures

Sending never throws into your app. Reading does, see [Reading totals](#reading-totals).

When sending goes wrong, the package logs:

- `error` when Bump refuses a batch (400, 401, 403) with the API's error code and message, when `BUMP_URL` answers with a redirect (never followed), when a batch can't be queued, and when retries run out
- `warning` for each event Bump rejects, with the field and the reason
- `warning` for a call the package drops itself: an unsaved model, a handle or id that's too long, two lists, an amount with more than 2 decimals, `amount` and `cents` together, text that isn't valid UTF-8
- `warning` when a job attempt fails and goes back on the queue: its events are dropped, because the next attempt records them again

Connection errors, 5xx and 429 are retried, waiting for `Retry-After` when Bump sends one, up to 15 minutes. Queued batches retry 5 times over about half an hour; sync delivery retries twice and waits at most 10 seconds at a time. Every event carries an id, so a retried batch never counts twice.

A job of your own that already sent some events (at 100 events, an explicit `flush()`, or a job the sync queue ran inside it) and then fails sends those again on its retry under new ids, so they count twice unless the calls pass `id:`.

A wrong argument type is still a PHP `TypeError` at the call, like with any other function. `Bump::track(['orders'])` throws.

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
                'cents' => $row->total_cents,
                'occurred_at' => $row->paid_at,
            ];
        }
    }
}
```

Events are in the API's item shape. `amount` and `cents` take what `amount:` and `cents:` take on `track()`, one of the two, and go out as an amount with two decimals. To change what the command generates, publish its stub with `php artisan vendor:publish --tag=bump-stubs` and edit `stubs/bump-backfill.stub`.

Live events without `id:` carry ids the package makes up, so Bump can't match them to the same events sent again with ids from your data. A backfill that sends anything from after live counting started counts it twice.

So every backfill runs up to `--until`: the moment live counting started. Bump shows it as Tracking since, in **Developers > Events** of the project's live environment. Copy it there and pass it as `--until`. The copied value carries its offset (`2026-10-16T14:02:11+02:00`), so it means the same moment in any app timezone.

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

Under `APP_ENV=testing`, which Laravel's `phpunit.xml` sets, `BUMP_ENABLED` defaults to `false`, so a test suite never reaches Bump by accident. To be explicit, add it to `phpunit.xml`:

```xml
<env name="BUMP_ENABLED" value="false"/>
```

To check what your code tracks, use `Bump::fake()`. It records calls and sends nothing. Use it instead of `Bump::shouldReceive()`, which skips the checks real delivery makes.

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

Also there: `assertTrackedTimes('orders', 2)`, `assertNothingTracked()`, and `tracked('orders')` for a collection of calls. For `forget()`: `assertForgotten($user)`, `assertNotForgotten($user)` and `forgotten()` for the handles. The fake records with or without a token, and with `BUMP_ENABLED` off.

The callback gets a `TrackedEvent` per call: `trackable`, `identifiables`, `count`, `amount`, `cents`, `occurredAt`, `note`, `activity`, `adjustment` and `id`. `amount` and `cents` are what the call passed, so `amount: 49.9` is `49.9` there, not the `"49.90"` that is sent. Models are resolved to handles like in real delivery, and a call real delivery would drop, an unsaved model or an amount with 3 decimals for example, is dropped here too: the failing assertion says why. `adjust()` calls count as tracked, with `adjustment` true and their defaults filled in. A field the call leaves out is `null`, and so is `id` when the package makes one up.

For code that reads totals, tell the fake what `total()` returns:

```php
Bump::fake()->withTotal('orders', count: 3, amount: 59.94);
```

The amount becomes the API's two-decimal string, so `59.9` is `"59.90"` and `cents` is `5990`. An amount with more decimals throws. The fake returns the total with or without a token and with `BUMP_ENABLED` off. A trackable you didn't set throws the same `BumpException` as a handle Bump doesn't know: `status` 404, `errorCode` `resource_missing`. Without the fake, `total()` throws in tests, since `BUMP_ENABLED` is off there.

## Known limits

- `track()` inside a database transaction that rolls back is still sent. Wrap it in `DB::afterCommit(fn () => Bump::track(...))` where that matters.
- Adding prefixed IDs to a model later changes its handle from `user_12` to `usr_3f9a…`, and its totals start again under the new one. If the model already has live events, set `BUMP_HANDLES=key` or give it a `bumpHandle()` first.
- `APP_ENV` must be exactly `production` to use the live token. `prod` or `live` use the test token.
- Mass updates and deletes (`Order::where(...)->update(...)`) skip Eloquent model events, so tracking done in observers or model events won't happen for them. Call `Bump::track()` or `Bump::adjust()` next to those queries.
- A single call whose `count`, `amount` or `cents` list holds more than 100 values is dropped with a warning: the API takes at most 100 events per request.

## AI agents

The package ships [Laravel Boost](https://github.com/laravel/boost) guidelines in `resources/boost/guidelines/core.blade.php`. Run `php artisan boost:update` after installing and pick `stickysoftware/bump-laravel` when asked, and your app's agents learn how to use it.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Development

```bash
composer test       # Pest
composer lint       # Pint
composer analyse    # PHPStan (Larastan)
```

The end-to-end tests send real requests and are skipped by default. Run them with a test token:

```bash
BUMP_E2E_TOKEN=bump_test_… vendor/bin/pest --group=end-to-end
```

`BUMP_E2E_URL` defaults to `https://api.bumpbunny.dev`. The forget test needs a token with **Can forget identifiables**, the read test one with **Can read totals**. Each is skipped without it.

## License

MIT. See [LICENSE.md](LICENSE.md).
