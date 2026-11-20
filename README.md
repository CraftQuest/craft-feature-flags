# Feature Flags for Craft CMS

Runtime feature flags for Craft CMS with targeting rules, percentage rollouts, and audit logging.

## Requirements

- Craft CMS 5.3.0 or later
- PHP 8.2 or later

## Installation

You can install this plugin from the Craft Plugin Store or with Composer.

### From the Plugin Store

Go to the Plugin Store in your Craft control panel, search for "Feature Flags", and click **Install**.

### With Composer

Run the following commands from your project directory for DDEV:

```bash
ddev composer require craftquest/craft-feature-flags
ddev craft plugin/install feature-flags
```

```bash
composer require craftquest/craft-feature-flags
php craft plugin/install feature-flags
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

On Craft 5.12 and later, Feature Flags adds tools to Craft's built-in MCP (Model Context Protocol) server, so AI assistants connected to your site can work with flags:

| Tool | What it does | Permission |
|---|---|---|
| `feature_flags_list`, `feature_flags_get` | List flags and read a flag's rules, rollout, schedule, and sites | View feature flags |
| `feature_flags_evaluate` | Check whether a flag is on for a user, site, or bucket key, and why | View feature flags |
| `feature_flags_audit_log` | Read the audit log | View feature flags |
| `feature_flags_toggle` | Turn a flag on or off | Toggle or Manage feature flags |
| `feature_flags_set_rollout`, `feature_flags_create`, `feature_flags_update`, `feature_flags_delete` | Change flags | Manage feature flags |

Tools act as the signed-in Craft user and respect that user's permissions. Changes are validated and recorded in the audit log exactly as they are in the control panel. There's nothing to configure: enable Craft's MCP server under **Settings → MCP**, and the tools appear for users who can view feature flags. They are never offered on Craft's public MCP endpoint.

## Documentation

Full documentation is available at [craftquest.io/plugins/feature-flags](https://craftquest.io/plugins/feature-flags).
