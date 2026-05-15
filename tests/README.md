# Tests

This directory contains pure-PHP unit tests that run without a Craft bootstrap.
They exercise regression-critical logic that does not depend on `Craft::$app`,
the database, or the plugin instance.

## Running

```bash
composer install
composer test
```

Or directly:

```bash
./vendor/bin/phpunit
```

## What's covered

| File | What it tests |
|------|--------------|
| `unit/BucketMathTest.php` | `EvaluationService::computeBucket()` - determinism, range, distribution, 50% split, edge cases |
| `unit/FlagTypeTest.php` | All `FlagType` enum cases and values |
| `unit/FlagNameRegexTest.php` | The flag name validation regex from `Flag::defineRules()` |

## What's NOT covered (intentionally)

Full integration paths that touch `Craft::$app`, the DB, or plugin singletons
are out of scope for these unit tests. Integration coverage should use
Codeception with the `craftcms/cms` test harness - see
<https://craftcms.com/docs/5.x/extend/testing.html>. That setup requires:

- A running database (MySQL or PostgreSQL)
- A Craft install fixture under `tests/_craft/`
- `codeception/module-yii2` and related dev dependencies
- Code coverage via `xdebug` or `pcov`

We've deferred that setup until someone needs a specific integration regression.
Until then, the unit tests here catch the things most likely to silently break:

- Percentage bucket math drifting (e.g., modulo off-by-one, hash change)
- Enum cases getting renamed without a DB migration
- Flag name regex becoming too strict or too loose
