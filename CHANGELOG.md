# Changelog

## 1.2.1 - Unreleased
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
