<?php

declare(strict_types=1);

namespace craftquest\featureflags\tests\unit;

use craftquest\featureflags\services\EvaluationService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the rollout strategy decision (EvaluationService::rolloutDecision).
 *
 *  - 'all'  : a matching targeting rule enables the flag outright; otherwise the rollout
 *             (if any) decides. Rules and rollout are independent OR paths.
 *  - 'rule' : targeting rules define the audience (no rules = everyone), then the rollout
 *             percentage filters within that audience.
 *
 * rolloutDecision($strategy, $ruleMatched, $rolloutPercentage, $inBucket) returns the final
 * enabled/disabled result once rule-matching and bucketing have been computed.
 */
final class RolloutStrategyTest extends TestCase
{
    // --- 'all' strategy ---

    public function testAllStrategyMatchedRuleEnablesRegardlessOfRollout(): void
    {
        self::assertTrue(EvaluationService::rolloutDecision('all', true, null, false));
        self::assertTrue(EvaluationService::rolloutDecision('all', true, 0, false));
        self::assertTrue(EvaluationService::rolloutDecision('all', true, 50, false));
    }

    public function testAllStrategyNoRuleFallsThroughToRollout(): void
    {
        self::assertTrue(EvaluationService::rolloutDecision('all', false, 50, true));   // in bucket
        self::assertFalse(EvaluationService::rolloutDecision('all', false, 50, false)); // out of bucket
    }

    public function testAllStrategyNoRuleNoRolloutIsOff(): void
    {
        self::assertFalse(EvaluationService::rolloutDecision('all', false, null, false));
        self::assertFalse(EvaluationService::rolloutDecision('all', false, 0, true)); // 0% never enables
    }

    // --- 'rule' strategy ---

    public function testRuleStrategyRequiresARuleMatch(): void
    {
        // No matching rule => off, even if the visitor would be in the rollout bucket.
        self::assertFalse(EvaluationService::rolloutDecision('rule', false, 50, true));
        self::assertFalse(EvaluationService::rolloutDecision('rule', false, null, true));
    }

    public function testRuleStrategyMatchedNoRolloutEnablesWholeAudience(): void
    {
        self::assertTrue(EvaluationService::rolloutDecision('rule', true, null, false));
    }

    public function testRuleStrategyMatchedZeroPercentIsOff(): void
    {
        self::assertFalse(EvaluationService::rolloutDecision('rule', true, 0, true));
    }

    public function testRuleStrategyMatchedAppliesRolloutWithinAudience(): void
    {
        self::assertTrue(EvaluationService::rolloutDecision('rule', true, 50, true));
        self::assertFalse(EvaluationService::rolloutDecision('rule', true, 50, false));
    }

    /**
     * The behavioral difference that motivates the feature: a flag with a userGroup rule
     * plus a 20% rollout. A non-member (no rule match) who happens to be in the bucket:
     *   - 'all'  => enabled (rollout is an independent path)
     *   - 'rule' => disabled (the rule gates the audience first)
     */
    public function testStrategyContrastForNonMatchingVisitorInBucket(): void
    {
        self::assertTrue(EvaluationService::rolloutDecision('all', false, 20, true));
        self::assertFalse(EvaluationService::rolloutDecision('rule', false, 20, true));
    }
}
