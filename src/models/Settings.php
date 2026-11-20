<?php

namespace craftquest\featureflags\models;

use CraftCms\Cms\Plugin\PluginSettings;

/**
 * Plugin settings, stored in project config under `plugins.feature-flags.settings`. Each can be
 * overridden per environment in `config/craft/feature-flags.php`.
 */
class Settings extends PluginSettings
{
    /** Seconds to cache each flag. 0 disables caching. */
    public int $cacheTtl = 60;

    /** Whether flag changes are recorded in the audit log. */
    public bool $enableAuditLog = true;

    /** Custom label for the CP nav item. */
    public ?string $pluginName = null;

    /** Name of the cookie that keeps anonymous visitors in their rollout bucket. */
    public string $anonymousCookieName = '_ff_vid';

    /** Lifetime of the visitor cookie, in seconds. 0 disables it. */
    public int $anonymousCookieTtl = 31536000;

    public function getRules(): array
    {
        return [
            'cacheTtl' => ['integer', 'min:0'],
            'enableAuditLog' => ['boolean'],
            'pluginName' => ['nullable', 'string', 'max:50'],
            // Must be a valid cookie name, or setting the visitor cookie fails mid-render.
            'anonymousCookieName' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'anonymousCookieTtl' => ['integer', 'min:0'],
        ];
    }
}
