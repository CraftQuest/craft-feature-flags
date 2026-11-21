# Feature Flags for Craft CMS

Runtime feature flags for Craft CMS with targeting rules, percentage rollouts, and audit logging.

## Requirements

- Craft CMS 6.0 or later
- PHP 8.5 or later

For Craft CMS 5, use Feature Flags 1.x (the `craft-5` branch).

## Installation

You can install this plugin from the Craft Plugin Store or with Composer.

### From the Plugin Store

Go to the Plugin Store in your Craft control panel, search for "Feature Flags", and click **Install**.

### With Composer

Run the following commands from your project directory for DDEV:

```bash
ddev composer require craftquest/craft-feature-flags
ddev artisan craft:plugin:install feature-flags
```

```bash
composer require craftquest/craft-feature-flags
php artisan craft:plugin:install feature-flags
```

## Usage

```twig
{% if craft.featureFlags.isEnabled('new-checkout') %}
    {# Show the redesigned checkout flow #}
    {% include '_checkout/new' %}
{% endif %}
```

```php
use craftquest\featureflags\FeatureFlags;

if (FeatureFlags::getInstance()->evaluationService->isEnabled('new-checkout')) {
    // Feature is enabled for the current user
}
```

## AI assistants (MCP)

Feature Flags adds tools to Craft's built-in MCP (Model Context Protocol) server, so AI assistants connected to your site can work with flags:

| Tool | What it does | Permission |
|---|---|---|
| `feature_flags_list`, `feature_flags_get` | List flags and read a flag's rules, rollout, schedule, and sites | View feature flags |
| `feature_flags_evaluate` | Check whether a flag is on for a user, site, or bucket key, and why | View feature flags |
| `feature_flags_audit_log` | Read the audit log | View feature flags |
| `feature_flags_toggle` | Turn a flag on or off | Toggle or Manage feature flags |
| `feature_flags_set_rollout`, `feature_flags_create`, `feature_flags_update`, `feature_flags_delete` | Change flags | Manage feature flags |

Tools act as the signed-in Craft user and respect that user's permissions. Changes are validated and recorded in the audit log exactly as they are in the control panel. There's nothing to configure: enable Craft's MCP server under **Settings → MCP**, and the tools appear for users who can view feature flags. They are never offered on Craft's public MCP endpoint.

## Upgrading from 1.x (Craft 5)

1. On Craft 5, update to the latest Feature Flags 1.x.
2. When you upgrade the site to Craft 6, require `craftquest/craft-feature-flags:^2.0`. Flags, rules, site settings, and the audit log carry over unchanged.
3. Check any `environment` rules. Craft 6 uses Laravel environment names, so a site that ran as `dev` usually runs as `local`, and rules with the value `dev` stop matching until you update them.
4. Move `config/feature-flags.php` to `config/craft/feature-flags.php`.
5. If you listen for Feature Flags events, switch from `Event::on()` to `Event::listen()` with the new event classes, and add `--force` to scheduled `cleanup-expired` runs.

Templates and PHP code that check flags need no changes, and anonymous visitors keep their rollout buckets. See the [changelog](CHANGELOG.md) for the full list.

## Documentation

Full documentation is available at [craftquest.io/plugins/feature-flags](https://craftquest.io/plugins/feature-flags).
