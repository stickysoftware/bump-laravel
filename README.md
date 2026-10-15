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
| `BUMP_QUEUE_CONNECTION` | app default | |
| `BUMP_QUEUE` | app default | |
| `BUMP_ENABLED` | `true` | `false` drops every call |

Create both tokens in the Bump dashboard, under the project's settings in live and in test. Production gets `BUMP_LIVE_TOKEN`, local and staging get `BUMP_TEST_TOKEN`.

Without the token for the current environment, the package is off: calls are accepted and dropped, with one `debug` log line per process.

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

Pass a string handle, a model, or a list mixing both. A model becomes its morph map alias, or its snake_case class name, plus its key: `user_12`, `team_member_3`.

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

With `BUMP_DELIVERY=queue` the buffer goes out as a queued job, so the app needs a running worker. Use `sync` for apps without one: the HTTP request then happens while the request or command terminates.

### Failures

The package never throws into your app. It logs instead:

- `error` when Bump refuses a batch (400, 401, 403) with the API's error code and message, when a batch can't be queued, and when retries run out
- `warning` for each event Bump rejects, with the field and the reason
- `warning` when a job attempt fails and goes back on the queue: its events are dropped, because the next attempt records them again

Connection errors, 5xx and 429 are retried, waiting for `Retry-After` when Bump sends one. Queued batches retry 5 times over about half an hour; sync delivery retries twice and waits at most 10 seconds at a time. Every event carries an id, so a retried batch never counts twice.

## Backfill

Import history with a class that yields events in the API's item shape:

```php
namespace App\Bump;

use App\Models\Order;
use StickySoftware\Bump\Contracts\Backfill;

class OrdersBackfill implements Backfill
{
    public function events(): iterable
    {
        foreach (Order::with('user')->lazy() as $order) {
            yield [
                'id' => "order-{$order->id}",
                'trackable' => 'orders',
                'identifiable' => $order->user,
                'amount' => $order->total,
                'occurred_at' => $order->paid_at,
            ];
        }
    }
}
```

```bash
php artisan bump:backfill "App\Bump\OrdersBackfill"
```

Every event needs an `id` built from your own data (max 64 characters). Running the backfill again then skips what was already sent, and an event without an `id` is rejected. Events go out in batches of 100, marked as backfill, so milestones they reach never fire integrations. The command ends with how many events were accepted, duplicate and rejected, and why.

## Known limits

- Mass updates and deletes (`Order::where(...)->update(...)`) skip Eloquent model events, so tracking done in observers or model events won't happen for them. Call `Bump::track()` or `Bump::adjust()` next to those queries.
- A single call whose `count` or `amount` list holds more than 100 values is dropped with a warning: the API takes at most 100 events per request.

## AI agents

The package ships [Laravel Boost](https://github.com/laravel/boost) guidelines in `resources/boost/guidelines/core.blade.php`. Run `php artisan boost:update` after installing and pick `stickysoftware/bump-laravel` when asked, and your app's agents learn how to use it.

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
