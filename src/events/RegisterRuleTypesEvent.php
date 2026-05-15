<?php

namespace craftquest\featureflags\events;

use yii\base\Event;

/**
 * RegisterRuleTypesEvent is used to register custom rule types for feature flags.
 *
 * Each rule type is an array with 'label' and 'value' keys:
 *
 * ```php
 * $event->ruleTypes[] = ['label' => 'IP Address', 'value' => 'ipAddress'];
 * ```
 */
class RegisterRuleTypesEvent extends Event
{
    /**
     * @var array<array{label: string, value: string}> The registered rule types.
     */
    public array $ruleTypes = [];
}
