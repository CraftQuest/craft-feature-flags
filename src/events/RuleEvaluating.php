<?php

namespace craftquest\featureflags\events;

use CraftCms\Cms\Shared\Concerns\HandleableEvent;
use CraftCms\Cms\User\Elements\User;
use craftquest\featureflags\models\Flag;
use craftquest\featureflags\models\Rule;

/**
 * Dispatched to evaluate a rule whose type isn't built in (see RuleTypesResolving).
 * Set `$handled = true` and `$matched` to the result.
 *
 * ```php
 * Event::listen(function (RuleEvaluating $event) {
 *     if ($event->rule->ruleType === 'ipAddress') {
 *         $event->handled = true;
 *         $event->matched = request()->ip() === $event->rule->ruleValue;
 *     }
 * });
 * ```
 *
 * @since 2.0.0
 */
class RuleEvaluating
{
    use HandleableEvent;

    public function __construct(
        public Flag $flag,
        public Rule $rule,
        public ?User $user = null,
        public bool $matched = false,
    ) {}
}
