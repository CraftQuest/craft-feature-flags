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

## Documentation

Full documentation is available at [craftquest.io/plugins/feature-flags](https://craftquest.io/plugins/feature-flags).
