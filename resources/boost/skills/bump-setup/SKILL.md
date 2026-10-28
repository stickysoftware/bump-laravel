---
name: bump-setup
description: "Adds Bump (stickysoftware/bump-laravel) to a Laravel app: surveys the app, asks the user what to track in two rounds of questions, then wires up the tracking calls, backfill and tests. Use when someone wants to add Bump or set it up, decide what to track (orders, signups, members, active users), or get the data in place for milestones."
license: MIT
metadata:
  author: sticky.software
---

# Set up Bump

Three phases, in order: survey, ask, implement. Nothing in the app changes before the user has answered both rounds of questions and confirmed the plan.

Two files hold the rules this skill builds on, both in `vendor/stickysoftware/bump-laravel/` (before the package is installed, read them at https://github.com/stickysoftware/bump-laravel):

- `README.md`: what the package does. Sections are named below.
- `resources/boost/guidelines/core.blade.php`: the guideline for calling the package (handles, amounts, ids, activity, tests). When Laravel Boost installed it, it's also in the app's `CLAUDE.md` or `AGENTS.md`. Follow it for every call you write.

## 1. Survey

Read only. Don't run anything that writes, and never read or print a token from `.env`.

Find:

- **The customer.** A user, a team or an organization: check which model owns orders and subscriptions (`belongsTo`, `user_id` / `team_id` columns).
- **Money.** `composer.json` for `laravel/cashier`, `laravel/cashier-mollie`, `mollie/laravel-mollie`, `stripe/stripe-php`; payment webhooks in `routes/`; payment and order models. Note how amounts are stored (cents int, decimal column, Mollie's `"10.00"` string), the currencies charged, and where refunds and credits happen.
- **Records.** Signups, invites, team members, and the app's core records being created.
- **Moments that aren't models.** Routes, controller actions, Livewire actions, jobs: checkout opened, export downloaded, invite sent, report generated. They're count-only and often a good activity signal.
- **Hooks.** Domain events, listeners and observers (`app/Events`, `app/Listeners`, `app/Observers`, Cashier's `WebhookReceived`).
- **Handles.** Whether models use `spatie/laravel-prefixed-ids`, and which ones.
- **Queue.** `QUEUE_CONNECTION` in `.env.example`, `laravel/horizon`, `laravel/octane`, a worker in the deploy config (`Procfile`, supervisor config, Forge or Cloud scripts, `docker-compose.yml`). The `dev` script in `composer.json` is local only.
- **History.** Which records could be backfilled, and the column the event happened at (`paid_at`, `created_at`). The local database isn't production, so never quote its earliest date as the app's.

For every candidate, keep the exact file:line where the call would go.

## 2. Ask, then stop

Use the harness's structured question tool when there is one (`AskUserQuestion` in Claude Code): at most 4 questions per call, at most 4 options per question. Put your recommendation first, with "(Recommended)" in its label. Give every option a one-line reason that names what you found, like "`Team`: orders and subscriptions belong to teams". Without such a tool, ask the same questions as a numbered list, recommendation first, and wait for the answers.

**Round 1**

1. **Who is the customer?** Team, user, or both. Both sends `identifiable: [$team, $user]`.
2. **Which records should Bump count?** Multi-select, the top 4 found, each naming the file it would hook into: "`orders`: paid orders, in `app/Listeners/MarkOrderPaid.php`".
3. **Which moments should Bump count?** Multi-select, the top 4 found. Leave this question out when you found none.
4. **How should refunds show up?** A negative amount on the same trackable with `Bump::adjust()`, so the order count stays and the amount drops; a separate trackable like `refunds`; or leave them out. Leave this question out when the app takes no money.

**Round 2**, after round 1 is answered:

1. **What makes someone active?** Multi-select, up to 3 of the trackables chosen in round 1 (the ones people cause directly first), plus "None". Activity drives Active today, Last 7 days and Last 30 days in Bump.
2. **Send past data?** "Yes, from the first record" or "No, start from today". Name the records and the column you'd read.
3. **Queue or sync?** Only when no queue worker was found. Recommend `sync`, unless the app runs Octane: then `queue`, and the app needs a worker.

Candidates beyond 4 don't go in the questions. They go below the plan table, where the user can add them.

**Hard stop.** No file changes, no `composer require`, until both rounds are answered. Then show the plan as one table:

| Trackable | Call goes in | Identifiables | Count / amount | Event id | Activity | Backfill |
|---|---|---|---|---|---|---|
| `orders` | `app/Listeners/MarkOrderPaid.php:24` | `$order->team` | 1, `cents: $order->total_cents` | `orders-{$order->id}` | yes | from first order, `paid_at` |

Below it, one line each: the label you'd send (`Team::bumpLabel()` returns the team name, or none), the `.env` changes, and the candidates you left out. If the app charges in more than one currency, say that Bump sums amounts in the project's one currency, and which amounts that affects. If the customer model has no prefixed ID, say that adding one later restarts its totals (README: Known limits). Ask the user to confirm or change it. Build only after they confirm.

Every question and message you show the user: plain words, short sentences, no em dashes, no hype.

## 3. Implement

1. **Package.** `composer require stickysoftware/bump-laravel` if it isn't in `composer.json` yet.
2. **Environment.** Add `BUMP_TEST_TOKEN=` and `BUMP_LIVE_TOKEN=`, both empty, to `.env` and `.env.example`, plus `BUMP_DELIVERY=sync` if the user chose sync. Then ask the user to make the token themselves: "In Bump, switch the sidebar to **Test**, open **Developers > API tokens** and create a token. Paste it after `BUMP_TEST_TOKEN=` in `.env`, not here." Never ask for a token in the chat, and never print, log or read one back. `php artisan about --only=bump` says whether it's set without showing it. If a token lands in the chat anyway, don't use or repeat it: ask the user to revoke it in Bump and make a new one.
3. **Calls.** Put each call where the thing happens, after the database commit: after `DB::transaction()` returns, or in `DB::afterCommit()` when it's inside one. Prefer the action, listener or webhook handler over a model's `created` observer, which also fires for seeders and imports. An observer you do use implements `Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit`. README: Known limits.
4. **Event ids.** Every call about a record gets an id from that record: `orders-{$order->id}`, `refunds-{$refund->id}`, `members-left-{$membership->id}`. A different event on the same record needs a different id. Something that repeats takes its id from what repeats: a monthly renewal from the invoice or payment, never the subscription, or Bump counts only the first. Use the same id in the live call and the backfill class, so an event sent both ways counts once. Moments without a record leave `id:` out. README: Event ids.
5. **Identifiables.** Pass the model and let `BUMP_HANDLES` (default `prefixed_id`) make the handle. Add `bumpHandle()` only if the user asked for it. Handles and labels never carry an email address, a person's name or other personal data. A team or company name as a label is fine: add `bumpLabel()` only if the plan says so. README: Identifiables.
6. **Amounts.** Cents ints go in `cents:`, decimals in `amount:`. README: Amounts.
7. **Refunds.** On the same trackable: `Bump::adjust()` with a negative amount and the refund's own id. As a separate trackable: `Bump::track('refunds', ...)` with a positive amount.
8. **Activity.** Which trackables count as activity is a switch per trackable in Bump, **Counts as activity**, on for a new one. Don't pass `activity` to turn a trackable off: the switch does that (step 11). Follow the guideline's Activity rules for the rest: nothing in a web request a person made, `activity: true` in a job or command run on a person's behalf, `activity: false` in a payment provider's webhook. README: Activity.
9. **Backfill.** If chosen: one class per kind of history with `php artisan make:bump-backfill`, filled in per README: Backfill, with the same ids as the live calls. Refund history on the same trackable sends what `adjust()` sends: `'count' => 0`, `'activity' => false` and a negative amount. Check each class with `php artisan bump:backfill "App\Bump\OrdersBackfill" --dry-run --until=now`: a dry run sends nothing and needs no token, so `now` is fine there and nowhere else. Don't run it for real here: it needs Tracking since from production.
10. **Tests.** One test per call site with `Bump::fake()` and `assertTracked()`, checking the identifiable, the amount and the id, plus a refund test. Use the app's test framework and style. README: Testing your app. Run them.
11. **One test event per trackable.** Once the user has pasted the token, tell them a project's timezone and currency are fixed after its first event, test events included, so check them under **Settings** first. Then send one event for each trackable in the plan, which also creates it in Bump:

    ```bash
    BUMP_DELIVERY=sync php artisan tinker --execute 'StickySoftware\Bump\Facades\Bump::track("orders", activity: false, id: "setup-check-orders");'
    ```

    Tell the user to open **Developers > Events** in Bump, sidebar on **Test**, to see them. If they aren't there, check `php artisan about --only=bump` and the Bump lines in `storage/logs/laravel.log`. Then name each trackable the plan doesn't count as activity, and ask the user to open it under **Trackables** and switch off **Counts as activity**, before production sends anything. Trackables and the switch are shared by live and test.

Finish with what changed and the deploy steps, each on its own line:

- Production `.env`: `BUMP_LIVE_TOKEN`, a token made with the sidebar on **Live**. It's used only when `APP_ENV` is exactly `production`. Staging gets a test token in `BUMP_TEST_TOKEN`, never the live one.
- In Bump, **Counts as activity** switched off for every trackable the plan doesn't count as activity, before the deploy.
- If sync was chosen: `BUMP_DELIVERY=sync` on production and staging. Otherwise a queue worker has to run there.
- Backfill, if chosen: after production sent its first events, copy **Tracking since** from **Developers > Events** on **Live** and run `php artisan bump:backfill ... --until="<Tracking since>"` on production, `--dry-run` first.
