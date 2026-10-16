# Upgrading

## From 0.1 to 0.2

A model without `bumpHandle()` now gets its prefixed ID from spatie/laravel-prefixed-ids as its handle (`usr_3f9a…`). In 0.1 it was type plus key (`user_12`). Models without a prefixed ID keep the 0.1 handle.

If the app already sent live events with 0.1, set this in production before deploying 0.2:

```
BUMP_HANDLES=key
```

Without it, those models get new handles in Bump and their totals start again from zero.

Apps that never sent live events can leave it out and use the new default.
