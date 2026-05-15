# Changelog

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
