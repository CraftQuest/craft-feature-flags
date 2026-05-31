# Changelog

## 1.1.0 - Unreleased
### Added
- Multi-site support: flags can apply to all sites (default) or be scoped to specific sites on multi-site installs ([#1](https://github.com/CraftQuest/craft-feature-flags/issues/1))
- New "Sites" section in the flag editor (shown only on multi-site installs) with an "All sites / Only specific sites" choice and a per-site switch
- Sites are an explicit opt-in: a flag scoped to specific sites is off on any site you don't enable, including sites added later
- Optional `siteId` argument on `isEnabled()` (PHP and Twig) to evaluate a flag for a specific site
- `Flag::$siteSettings` model property

### Changed
- Evaluation now applies a per-site gate (after the master switch, before targeting rules) on multi-site installs. Targeting rules are evaluated only on sites where the flag is enabled
- Per-site evaluation fails closed for site-scoped flags when no site can be resolved (console/queue without an explicit `siteId`, or an unknown `siteId`); pass an explicit, valid `siteId` for a reliable per-site answer outside web requests
- The per-request evaluation cache key now includes the requested site context
- Cache version bumped (stored flag objects are refreshed on upgrade)

### Fixed
- The "Subscription Plan" rule type now appears in the flag editor when Craft Commerce is installed (it was previously always hidden because of a missing import)

### Notes
- Existing flags and single-site installs are unaffected: a flag with no per-site settings stays enabled on all sites

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
