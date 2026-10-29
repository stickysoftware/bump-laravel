# Changelog

All notable changes to `stickysoftware/bump-laravel` are documented in this file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## 1.3.1 - Unreleased

`bump-setup` changes:

- It only reads the app until the plan is confirmed, including the package install and the Boost update. The confirm is a question: build it, change something, or not now.
- It runs tests only against a separate test database. In a new worktree without `.env.testing`, Laravel falls back to `.env`, and a test that refreshes the database empties the development one.
- Active means someone used the app. The activity question recommends the actions people come for (in a shop, placing an order), not signups, invites or renewals the app charges on its own, and the reminder to switch off **Counts as activity** names the user's answer.
- The plan suggests a line for the app's privacy policy, and the skill leaves the policy itself to the user.
- Model prefixes are read from where the app registers them, never guessed.

Guideline:

- A payment webhook for a checkout someone just paid leaves activity to the trackable's setting. Only a webhook nobody caused just then, like an automatic renewal, sends `activity: false`.
- Event id examples use the plural form (`orders-1042`, `refunds-77`), like the README.

## 1.3.0 - 2026-10-29

- A Laravel Boost skill, `bump-setup`, that walks a coding agent through adding Bump to an app: it reads the app, asks what to track, then adds the calls, backfill and tests. `php artisan boost:update` installs it with the guideline. Without Boost, an agent can read it from `resources/boost/skills/bump-setup/SKILL.md`.

## 1.2.0 - 2026-10-26

- `cents:` on `Bump::track()` and `Bump::adjust()`, for whole cents like a Cashier amount or a `*_cents` column: `cents: 4999` sends `"49.99"`. It takes an int or a list, and comes last, so calls that pass arguments by position keep working. Backfill events take a `cents` key the same way.
- Every amount is sent as a string with two decimals: `49.99` as `"49.99"`, `50.0` as `"50.00"`. Float noise like `0.1 + 0.2` goes out as `"0.30"`.
- An amount with more than 2 decimals, like `49.999`, is dropped with a warning instead of sent. So is a call with both `amount` and `cents`.
- `Total->cents` gives a total's amount in cents, as an int.
- `TrackedEvent->cents` in `Bump::fake()`. `TrackedEvent->amount` stays what the call passed.

### Upgrading

- An int in `amount:` is sent as it is, and Bump now refuses it, since a whole number could be cents. So is a string without decimals like `'50'`. Pass whole cents with `cents:`, and a whole amount as a float or a string with decimals: `amount: 50.0` or `amount: '50.00'`. The same goes for `amount` in backfill events.

## 1.1.0 - 2026-10-22

- `Bump::total()` reads one trackable's totals for the whole project. It needs a token made with **Can read totals**.
- `StickySoftware\Bump\Total` holds them: `handle`, `count`, `amount` (a string with two decimals, like `"59.94"`), `currency` and `lastEventAt`.
- A failed read throws `StickySoftware\Bump\Exceptions\BumpException`, with `status`, `errorCode` and `retryAfter`. Sending still never throws.
- `withTotal()` on `Bump::fake()` sets what `total()` returns in tests.
- `BUMP_SYNC_TIMEOUT` also limits a read.

## 1.0.0 - 2026-10-22

First public release.

- `Bump::track()` and `Bump::adjust()`, buffered and sent once per request, command or queued job, or earlier at 100 events.
- `id:` on both, for your own idempotency key. Without it, every event gets a UUID.
- `Bump::forget()` to erase an identifiable, after the events recorded so far.
- Models become handles: `bumpHandle()`, their spatie/laravel-prefixed-ids ID, or type plus key. Labels from `bumpLabel()`.
- Queue delivery (default) and sync delivery, with retries that honour `Retry-After`. The package never throws into the app.
- `BUMP_URL` has to use https. Plain http works only for localhost, 127.0.0.1, ::1 and hosts ending in `.test` or `.localhost`.
- Queued jobs are encrypted with `APP_KEY`.
- Redirects are never followed: a `3xx` from `BUMP_URL` is a failed send.
- An event with text that isn't valid UTF-8 is dropped with a warning, without taking the rest of its batch down.
- A `Retry-After` from the API is honoured up to 15 minutes.
- Activity left to the trackable's setting in web requests, `false` in jobs and commands.
- `php artisan make:bump-backfill` and `php artisan bump:backfill` to import history up to when live counting started.
- `Bump::fake()` with assertions for tracked and forgotten calls.
- A Bump section in `php artisan about`, and Laravel Boost guidelines.

### Upgrading from a pre-release

- `BUMP_URL` is now the host alone, and the package adds `/v1`. If `.env` sets it, change `BUMP_URL=https://api.bumpbunny.dev/v1` to `BUMP_URL=https://api.bumpbunny.dev`, or remove it. A URL that still ends in `/v1` keeps working and logs one debug line per process.
- `BUMP_ENABLED` defaults to `false` under `APP_ENV=testing`.
- The class behind the facade is now `StickySoftware\Bump\Tracker`. Code that resolves `StickySoftware\Bump\Bump` from the container has to use the new name.
