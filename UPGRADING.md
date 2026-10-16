# Upgrading

## From 0.3 to 0.4

`Backfill::events()` takes a `BackfillWindow`, and `bump:backfill` needs `--until`: when live counting started. Update each backfill class:

```php
use StickySoftware\Bump\Backfill\BackfillWindow;

public function events(BackfillWindow $window): iterable
{
    $orders = $window->constrain(Order::query(), 'paid_at')->lazyById();

    // ...
}
```

If the class read a cutoff of its own, replace `where('paid_at', '<', $cutoff)` with `$window->constrain($query, 'paid_at')`, or use `$window->until` where `constrain()` doesn't fit. Both are in the app timezone, like a cutoff that worked with wall-clock columns.

Every event now needs an `occurred_at`. Bump would date an event without one now, after the window, so the command rejects it.

`--until` is Tracking since, copied from Developers > Events in the project's live environment in Bump. A wrapper command that set the cutoff and ran several classes can go. Run them directly:

```bash
php artisan bump:backfill "App\Bump\OrdersBackfill" "App\Bump\RefundsBackfill" --until="2026-10-16T14:02:11+02:00"
```

The command shows the window, asks before sending (`--force` skips that), stops at the first class Bump doesn't take a batch from, and rejects events dated outside the window. `--dry-run` counts without sending.

## From 0.1 to 0.2

A model without `bumpHandle()` now gets its prefixed ID from spatie/laravel-prefixed-ids as its handle (`usr_3f9a…`). In 0.1 it was type plus key (`user_12`). Models without a prefixed ID keep the 0.1 handle.

If the app already sent live events with 0.1, set this in production before deploying 0.2:

```
BUMP_HANDLES=key
```

Without it, those models get new handles in Bump and their totals start again from zero.

Apps that never sent live events can leave it out and use the new default.
