# Changelog

All notable changes to `stickysoftware/bump-laravel` are documented in this file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## 1.1.0 - Unreleased

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
