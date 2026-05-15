<?php

namespace craftquest\featureflags\models;

use craft\base\Model;

class Rule extends Model
{
    public ?int $id = null;
    public ?int $flagId = null;
    public ?string $ruleType = null;
    public ?string $ruleValue = null;

    protected function defineRules(): array
    {
        return [
            [['ruleType', 'ruleValue'], 'required'],
            [['ruleType'], 'string', 'max' => 50],
            [['ruleValue'], 'string', 'max' => 255],
        ];
    }
}
