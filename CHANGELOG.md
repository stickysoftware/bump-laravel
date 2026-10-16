# Changelog

All notable changes to `stickysoftware/bump-laravel` are documented in this file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## 0.4.0 - 2026-10-16

### Changed

- Breaking: `Backfill::events()` takes a `StickySoftware\Bump\Backfill\BackfillWindow`. See `UPGRADING.md`.
- Breaking: `bump:backfill` needs `--until`, when live counting started, and refuses a time in the future. Events after it were already counted live, so a backfill that sends them counts them twice.
- Breaking: a backfill event without `occurred_at` is rejected (`occurred_at is required for a backfill.`). Bump would date it now, after the window.
- `bump:backfill` takes several classes and runs them in order, stopping at the first one Bump doesn't take a batch from.
- `bump:backfill` shows the window in the app timezone and in UTC and asks before sending.

### Added

- `BackfillWindow`, with `until` and `since` in the app timezone and `constrain($query, $column)`: since included, until not, so consecutive windows meet exactly.
- `--since` to send history in slices, `--force` to skip the question, and `--dry-run` to count per class without sending or a token.
- `bump:backfill` rejects an event whose `occurred_at` falls outside the window.

## 0.3.0 - 2026-10-16

### Added

- `Bump::fake()` for tests: records calls and sends nothing. Assert with `assertTracked`, `assertTrackedTimes`, `assertNotTracked` and `assertNothingTracked`, or read calls with `tracked()`. Callbacks get a `TrackedEvent` with model handles resolved the way real delivery resolves them.
- A Bump section in `php artisan about`: enabled, which token the environment uses and whether it is set, delivery, handles and the API URL.
- `.gitattributes`, so tests and tooling config stay out of installed copies.
- This changelog and `UPGRADING.md`.

## 0.2.0 - 2026-10-15

### Changed

- A model without `bumpHandle()` now defaults to its prefixed ID from spatie/laravel-prefixed-ids as its handle (`usr_3f9a…`), instead of type plus key (`user_12`). Apps that sent live events with 0.1 should read `UPGRADING.md`.

### Added

- `BUMP_HANDLES`: `prefixed_id` (default) or `key`. An unknown value acts as `key` and logs one warning.
- A model loaded without its prefixed ID column (`select('id')`) fetches it with one query, without scopes, so soft-deleted rows resolve too.
- A model whose prefixed ID is empty falls back to type plus key, with one warning per model class.
- A handle longer than 64 characters drops its event with a warning.

## 0.1.0 - 2026-10-15

### Added

- `Bump::track()` and `Bump::adjust()`. `adjust()` sends `count: 0` and `activity: false` unless the call passes them.
- Calls are buffered and sent once per web request, console command or queued job, or earlier at 100 events.
- Models become handles from their morph map alias or class name plus key, or from `bumpHandle()`. Labels only from `bumpLabel()`.
- Web requests leave activity to the trackable's setting. Queued jobs and console commands send `activity: false`.
- Queue delivery (default) and sync delivery. Connection errors, 5xx and 429 are retried, honouring `Retry-After`. 400, 401 and 403 are logged and never retried.
- `BUMP_SYNC_TIMEOUT` (default 5 seconds) for sync delivery, with a warning when an answer takes at least half of it.
- `BUMP_LIVE_TOKEN` in production, `BUMP_TEST_TOKEN` everywhere else. Without the token for the environment, calls are dropped.
- `php artisan bump:backfill` to import history in batches of 100, marked as backfill.
- Laravel Boost guidelines.
