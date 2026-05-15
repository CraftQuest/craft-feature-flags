<?php

namespace craftquest\featureflags\events;

use craft\elements\User;
use craftquest\featureflags\models\Flag;
use craftquest\featureflags\models\Rule;
use craftquest\featureflags\services\EvaluationService;
use yii\base\Event;

/**
 * EvaluateRuleEvent is fired when a rule type is not handled by the built-in evaluator.
 *
 * Set `$matched` to true/false and `$handled` to true to indicate your handler processed the rule.
 *
 * ```php
 * Event::on(
 *     EvaluationService::class,
 *     EvaluationService::EVENT_EVALUATE_RULE,
 *     function (EvaluateRuleEvent $event) {
 *         if ($event->rule->ruleType === 'ipAddress') {
 *             $event->matched = Craft::$app->getRequest()->getUserIP() === $event->rule->ruleValue;
 *             $event->handled = true;
 *         }
 *     }
 * );
 * ```
 */
class EvaluateRuleEvent extends Event
{
    public Flag $flag;
    public Rule $rule;
    public ?User $user = null;
    public bool $matched = false;
    public bool $handled = false;
}
