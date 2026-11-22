# Changelog

## 2.0.0-beta.1 - 2026-11-21

> [!IMPORTANT]
> **Upgrading from 1.x**
> - Feature Flags 2.0 requires Craft CMS 6 and PHP 8.5. Upgrade the site to the latest Feature Flags 1.x on Craft 5 first, then require `craftquest/craft-feature-flags:^2.0` when you upgrade to Craft 6. Your flags, rules, site settings, and audit log carry over unchanged, with no data migration
> - **Environment rules:** Craft 6 uses Laravel's environment names. A site that ran with `CRAFT_ENVIRONMENT=dev` typically runs with `APP_ENV=local`, so an `environment` rule with the value `dev` stops matching. Update those rules to the new environment name (`php artisan about` shows it)
> - **Config file:** move `config/feature-flags.php` to `config/craft/feature-flags.php`
> - **Events:** listeners registered with `Event::on()` must move to `Event::listen()` with the new event classes (see below)
> - **Console:** scheduled `cleanup-expired` runs need `--force`
> - Anonymous visitors keep their rollout buckets: the Craft 5 visitor cookie is read once and re-issued in the Craft 6 format
> - Templates (`craft.featureFlags.isEnabled()`) and PHP calls through `FeatureFlags::getInstance()->evaluationService` need no changes

### Changed
- **Breaking:** the plugin's events are now native Laravel event classes. Register listeners with `Event::listen()` (Laravel) instead of Yii's `Event::on()`. Listener code can stay the same: the event properties are unchanged.
  - `FlagService::EVENT_BEFORE_SAVE_FLAG` → `events\FlagSaving` (set `$isValid = false` to cancel)
  - `FlagService::EVENT_AFTER_SAVE_FLAG` → `events\FlagSaved`
  - `FlagService::EVENT_AFTER_TOGGLE_FLAG` → `events\FlagToggled`
  - `FlagService::EVENT_AFTER_DELETE_FLAG` → `events\FlagDeleted`
  - `FeatureFlags::EVENT_REGISTER_RULE_TYPES` → `events\RuleTypesResolving`
  - `EvaluationService::EVENT_EVALUATE_RULE` → `events\RuleEvaluating`
- Feature Flags 2.0 requires Craft CMS 6 and PHP 8.5, and is built natively on Craft 6 (no `craftcms/yii2-adapter`). Sites on Craft 5 should stay on Feature Flags 1.x
- The control panel screens are rebuilt with Craft 6's UI. To turn flags on or off from the list, select them and choose a status
- MCP tools now register with Craft 6's MCP server
- Console commands are now Artisan commands (`php artisan craft:feature-flags:flags:list`, etc.). The 1.x names (`php craft feature-flags/flags/list`) still work as aliases, and boolean options accept both `--enabled` and `--enabled=1`
- `feature-flags/flags/cleanup-expired` no longer deletes anything in a non-interactive run (cron, CI) unless you pass `--force`. Under Craft 5 it confirmed automatically
- Config file overrides move from `config/feature-flags.php` to `config/craft/feature-flags.php`
- Plugin settings now use Craft's standard plugin settings screen (Settings → Plugins → Feature Flags)
- The anonymous visitor cookie (`_ff_vid`) is now set and read through Laravel, and encrypted with the app key
- `EvaluationService::explain()` and the MCP `feature_flags_evaluate` tool no longer fall back to the current request's site. Pass `siteId` to evaluate a flag that's scoped to specific sites. This gives the same answer over MCP's HTTP and stdio servers

### Fixed
- Visitors keep their rollout buckets when a site upgrades from Craft 5. The Craft 5 visitor cookie is read once and re-issued in the Craft 6 format, instead of every anonymous visitor being assigned a new bucket
- Anonymous visitors stay in the same rollout bucket across page loads on Craft 6 (the visitor cookie was not being sent)
- Queue jobs run by Laravel's queue worker start with fresh flag results, as they do on Craft 5

## 1.2.1 - 2026-11-20
### Fixed
- When saving a flag in the control panel fails validation (for example, a handle that's already in use), the form now keeps what you entered and shows the field errors, instead of reloading the saved flag or an empty form
- An existing flag whose handle is cleared now fails validation instead of silently getting a new handle generated from its name, which would turn it off everywhere the old handle is checked
- MCP: `feature_flags_update` now rejects `null` for `newHandle`, `name`, `rolloutStrategy`, and `rules` instead of treating it as a change. Previously, `newHandle: null` renamed the flag
- MCP: dates without a time zone offset are now interpreted in the site's time zone, as in the control panel, instead of UTC. Only ISO 8601 dates are accepted
- MCP: flags scoped to specific sites now store an explicit setting for every site, so deleting the only enabled site no longer turns the flag on for all sites
- An empty bucket key (for example `isEnabled('handle', account.id ?? '')`) is now treated like no bucket key, so logged-in users are bucketed by their user ID instead of the anonymous visitor cookie
- Queue workers and other long-running processes no longer keep returning a flag's old result after it changes. Evaluation results are cleared when a flag is saved, toggled, or deleted, and at the start of every queue job

## 1.2.0 - 2026-11-19
### Added
- Support for Craft's native MCP server (Craft 5.12+). AI assistants connected to Craft can list, inspect, evaluate, toggle, create, update, and delete flags, ramp rollouts, and read the audit log through `feature_flags_*` tools. Tools follow the user's Feature Flags permissions and are recorded in the audit log. Nothing changes on earlier Craft versions
- Optional **start date** per flag. A flag evaluates as off before its start date, pairing with the existing expiration date to define a full active window. Both are optional and independent ([#2](https://github.com/CraftQuest/craft-feature-flags/issues/2))
- Quick toggle: flip a flag's master switch directly from the flag list, with the status updating in place ([#3](https://github.com/CraftQuest/craft-feature-flags/issues/3))
- New `featureFlags:toggle` permission so editors and support staff can flip flags without being able to edit rules, rollout, or sites. The toggle action accepts either `toggle` or `manage` ([#17](https://github.com/CraftQuest/craft-feature-flags/issues/17))
- `FlagService` now fires `EVENT_BEFORE_SAVE_FLAG` (cancelable), `EVENT_AFTER_SAVE_FLAG`, `EVENT_AFTER_TOGGLE_FLAG`, and `EVENT_AFTER_DELETE_FLAG`, each carrying a `FlagEvent` with the flag model, so modules can purge static caches or react to flag changes ([#6](https://github.com/CraftQuest/craft-feature-flags/issues/6))
- Console: `feature-flags/flags/create <handle>` with `--name`, `--type`, `--enabled`, and `--description` options, for creating flags from deploy scripts and migrations ([#19](https://github.com/CraftQuest/craft-feature-flags/issues/19))
- Console: `feature-flags/flags/set-rollout <handle> <percentage>` to ramp a rollout from CI or cron; pass `none` to remove it ([#19](https://github.com/CraftQuest/craft-feature-flags/issues/19))
- Console: `info` now lists the sites a flag is enabled on for multi-site installs ([#19](https://github.com/CraftQuest/craft-feature-flags/issues/19))
- Copyable Twig, PHP, and console usage snippets in the flag edit page sidebar ([#15](https://github.com/CraftQuest/craft-feature-flags/issues/15))
- The flag edit page now warns when you change an existing flag's handle, since templates still checking the old handle will silently get `false`. The previous handle is also recorded in the audit entry ([#16](https://github.com/CraftQuest/craft-feature-flags/issues/16))
- When `devMode` is on, checking a flag handle that doesn't exist now logs a warning so typos aren't mistaken for disabled flags ([#14](https://github.com/CraftQuest/craft-feature-flags/issues/14))
- `EvaluationService::explain()` evaluates a flag for a given user, site, and bucket key and returns the result with the reason (for example `disabled`, `not_started`, `rule_matched`, or `rollout_out_of_bucket`) and the visitor's rollout bucket
- `Flag::$startsAt` model property; `EvaluationService::isOutsideSchedule()` static helper

### Changed
- Validation now rejects an expiration date that is not after the start date
- Cache version bumped (stored flag objects are refreshed on upgrade)

### Fixed
- On multi-site installs, choosing “Only specific sites” without switching on any site now fails validation instead of silently disabling the flag everywhere ([#20](https://github.com/CraftQuest/craft-feature-flags/issues/20))
- Console commands no longer crash with an “Undefined constant” error when they fail (for example, an unknown flag handle or invalid input). They now print the error and exit with status 1

## 1.1.0 - 2026-06-05
### Added
- Multi-site support: flags can apply to all sites (default) or be scoped to specific sites on multi-site installs ([#1](https://github.com/CraftQuest/craft-feature-flags/issues/1))
- Sites are an explicit opt-in: a flag scoped to specific sites is off on any site you don't enable, including sites added later
- Optional `siteId` argument on `isEnabled()` (PHP and Twig) to evaluate a flag for a specific site
- `Flag::$siteSettings` model property
- Rollout strategy per flag: **All visitors** (targeting rules and rollout are independent paths — the default) or **Rule-matched only** (the rollout percentage filters within the rule-matched audience)

### Changed
- Evaluation now applies a per-site gate (after the master switch, before targeting rules) on multi-site installs. Targeting rules are evaluated only on sites where the flag is enabled
- Per-site evaluation fails closed for site-scoped flags when no site can be resolved (console/queue without an explicit `siteId`, or an unknown `siteId`); pass an explicit, valid `siteId` for a reliable per-site answer outside web requests
- The per-request evaluation cache key now includes the requested site context
- Cache version bumped (stored flag objects are refreshed on upgrade)

### Fixed
- The "Subscription Plan" rule type now appears in the flag editor when Craft Commerce is installed (it was previously always hidden because of a missing import)

## 1.0.1 - 2026-05-29
### Fixed
- Save and edit with keyboard combo fixed for Flags

### Added
- Save and add another keyboard combo for Flags

## 1.0.0 - 2026-05-15

### Added
- Feature flag management with enable/disable toggle
- Flag types: release, experiment, ops, permission
- Human-readable name and kebab-case handle for each flag
- Targeting rules: user ID, user group, environment
- Subscription plan targeting (requires Craft Commerce)
- Percentage-based rollout with consistent user bucketing
- Anonymous visitor bucketing via `$bucketKey` parameter and configurable cookie
- `EvaluationService::computeBucket()` static helper for rollout hash
- Optional flag expiration dates
- Three-layer caching: per-request, application cache, database
- Configurable cache TTL (default 60 seconds)
- Versioned cache keys via `FlagService::CACHE_VERSION`
- Audit logging with user attribution
- Configurable audit log toggle
- Custom plugin name setting
- Extensible rule types via `RegisterRuleTypesEvent`
- Custom rule evaluation via `EvaluateRuleEvent`
- Twig variable: `craft.featureFlags.isEnabled('flag-handle')`
- PHP API: `FeatureFlags::getInstance()->evaluationService->isEnabled('flag-handle')`
- CP permissions: view and manage
- Console commands: list, info, enable, disable, delete, cleanup-expired
- PHPUnit test suite covering bucket math, distribution, handle generation, and flag types
