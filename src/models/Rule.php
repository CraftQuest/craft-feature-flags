<?php

namespace craftquest\featureflags\models;

use CraftCms\Cms\Component\Component;

class Rule extends Component
{
    public ?int $id = null;
    public ?int $flagId = null;
    public ?string $ruleType = null;
    public ?string $ruleValue = null;

    public function getRules(): array
    {
        return [
            'ruleType' => ['required', 'string', 'max:50'],
            'ruleValue' => ['required', 'string', 'max:255'],
        ];
    }
}
