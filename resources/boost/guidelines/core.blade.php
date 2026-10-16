# Bump

This app sends events to Bump with `stickysoftware/bump-laravel`. Bump keeps exact running totals per trackable, per identifiable and project-wide, and fires milestones when totals cross objectives.

## Tracking

- Call `Bump::track()` (facade `StickySoftware\Bump\Facades\Bump`) where the thing happens: an order is paid, a member joins. Calls are buffered and sent once per request, command or job. They never throw into the app.
- Use `Bump::adjust()` for corrections: refunds, removals, fixes. It sends `count: 0` and `activity: false` unless you pass them, so it changes totals without counting a new occurrence or an active user.
- Trackable handles are lowercase `[a-z0-9_.-]`, max 64 characters, plural nouns: `orders`, `members`.
- `count` defaults to 1 and `amount` to 0 on the server. Pass both when both matter: an order is one order and an amount. Amounts take at most 2 decimals, in the project currency.
- Totals are additive. Never send a new total; send the difference, negative when it goes down.

@boostsnippet('Track and adjust', 'php')
use StickySoftware\Bump\Facades\Bump;

Bump::track('orders', identifiable: $order->user, amount: $order->total);

Bump::track('members', identifiable: $team, count: -1);

Bump::adjust('orders', identifiable: $order->user, amount: -25.00, note: 'partial refund');
@endboostsnippet

## Activity

- An event counts as activity (DAU/WAU/MAU) by the trackable's setting in the Bump dashboard.
- In web requests the package sends no `activity` field, so the setting applies. In queued jobs and console commands it sends `activity: false`, because there the system acted, not a person.
- Pass `activity: true` in a job or command that runs on a person's behalf (for example a job processing an upload they just made). Pass `activity: false` in a web request a person didn't cause (for example a webhook from a payment provider).

## Identifiables

- Pass the Eloquent model, not its id: `identifiable: $user`. When the app uses `spatie/laravel-prefixed-ids`, the handle is the model's prefixed ID (`usr_3f9a…`). Otherwise it's the morph map alias or the snake_case class name plus the key: `user_12`, `team_8`.
- Never change `BUMP_HANDLES` once events are live: every model would get a new handle, with totals from zero.
- Pass a list for an event that belongs to several: `identifiable: [$user, $team]`.
- Define `bumpHandle(): string` on the model to change its handle. Keep handles stable: a changed handle is a new identifiable with totals from zero.
- Define `bumpLabel(): ?string` on the model to send a display label. Without it, no label is sent.
- Never put an email address in a handle or a label. Company and team names are fine as labels. Person names only when the app's owner has decided to send them.

## Environments

- `APP_ENV=production` sends with `BUMP_LIVE_TOKEN`, every other environment with `BUMP_TEST_TOKEN`. Without the token for the current environment, calls are dropped.
- Production `.env`: `BUMP_LIVE_TOKEN`. Local and staging `.env`: `BUMP_TEST_TOKEN`. Never put the live token in a non-production environment.
- `BUMP_DELIVERY=queue` (default) needs a running queue worker. Use `sync` only for apps without one.

## Backfill

- To import history, write a class implementing `StickySoftware\Bump\Contracts\Backfill` and run `php artisan bump:backfill "App\Bump\OrdersBackfill"`.
- Every event needs an `id` derived from the app's own data (`order-1042`, `refund-77`), max 64 characters. Never a random UUID: the same id makes a rerun skip what was already sent.
- Iterate with `lazy()` or `cursor()` so large tables don't load into memory.

@boostsnippet('Backfill class', 'php')
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
@endboostsnippet

## Testing

- In feature tests that track, call `Bump::fake()` first, then assert with `Bump::assertTracked()`, `assertTrackedTimes()`, `assertNotTracked()` or `assertNothingTracked()`. Callbacks get a `StickySoftware\Bump\Testing\TrackedEvent`.
- Never hit the real Bump API from tests: fake it, and keep `BUMP_TEST_TOKEN` and `BUMP_LIVE_TOKEN` out of `phpunit.xml` and `.env.testing`.

@boostsnippet('Fake Bump in a test', 'php')
use StickySoftware\Bump\Facades\Bump;
use StickySoftware\Bump\Testing\TrackedEvent;

Bump::fake();

$this->post("/orders/{$order->id}/pay");

Bump::assertTracked('orders', fn (TrackedEvent $event) => $event->hasIdentifiable($order->user));
Bump::assertNotTracked('refunds');
@endboostsnippet

## Known limit

- Mass updates and deletes (`Order::where(...)->update(...)`, `->delete()`) skip model events, so nothing tracked in observers or model events fires for them. Call `Bump::track()` or `Bump::adjust()` yourself next to such queries.
