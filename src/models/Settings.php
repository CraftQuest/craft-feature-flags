<?php

namespace craftquest\featureflags\models;

use craft\base\Model;

class Settings extends Model
{
    public int $cacheTtl = 60;
    public bool $enableAuditLog = true;
    public ?string $pluginName = null;
    public string $anonymousCookieName = '_ff_vid';
    public int $anonymousCookieTtl = 31536000;

    protected function defineRules(): array
    {
        return [
            [['cacheTtl'], 'integer', 'min' => 0],
            [['enableAuditLog'], 'boolean'],
            [['pluginName'], 'string', 'max' => 50],
            [['anonymousCookieName'], 'string', 'max' => 50],
            [['anonymousCookieTtl'], 'integer', 'min' => 0],
        ];
    }
}
