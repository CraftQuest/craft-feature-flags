<?php

namespace craftquest\featureflags\events;

/**
 * Dispatched when building the list of targeting rule types. Add an entry to register a
 * custom rule type, then evaluate it by listening for RuleEvaluating.
 *
 * ```php
 * Event::listen(function (RuleTypesResolving $event) {
 *     $event->ruleTypes[] = ['label' => 'IP address', 'value' => 'ipAddress'];
 * });
 * ```
 *
 * @since 2.0.0
 */
class RuleTypesResolving
{
    /**
     * @param array<int, array{label: string, value: string}> $ruleTypes
     */
    public function __construct(
        public array $ruleTypes = [],
    ) {}
}
