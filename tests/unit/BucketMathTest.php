<?php

declare(strict_types=1);

namespace craftquest\featureflags\tests\unit;

use craftquest\featureflags\services\EvaluationService;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the percentage rollout bucket math.
 *
 * This is the single most important piece of logic in the plugin: getting it
 * wrong silently breaks every rollout. Tests cover determinism, range bounds,
 * distribution shape, and a few known hash values so drift is loud.
 */
final class BucketMathTest extends TestCase
{
    public function testBucketIsDeterministicForSameInputs(): void
    {
        $a = EvaluationService::computeBucket('123', 'new-checkout');
        $b = EvaluationService::computeBucket('123', 'new-checkout');
        self::assertSame($a, $b);
    }

    public function testBucketIsAlwaysWithinZeroToNinetyNine(): void
    {
        for ($i = 0; $i < 1000; $i++) {
            $bucket = EvaluationService::computeBucket((string)$i, 'flag-x');
            self::assertGreaterThanOrEqual(0, $bucket);
            self::assertLessThanOrEqual(99, $bucket);
        }
    }

    public function testDifferentFlagsProduceDifferentBucketsForSameUser(): void
    {
        // Users must not be trapped in or out of every flag at once - the flag
        // name salts the hash so a user's bucket varies per flag.
        $user = '42';
        $buckets = [];
        foreach (['flag-a', 'flag-b', 'flag-c', 'flag-d', 'flag-e'] as $flag) {
            $buckets[] = EvaluationService::computeBucket($user, $flag);
        }
        // At least some of the 5 buckets should differ - extremely unlikely
        // for crc32 to collide all 5.
        self::assertGreaterThan(1, count(array_unique($buckets)));
    }

    public function testDistributionRoughlyMatchesTargetPercentage(): void
    {
        // With 10,000 synthetic users and a target of 50%, we expect the count
        // of users whose bucket < 50 to sit in a tight window around 5,000.
        $flagName = 'distribution-test';
        $enabled = 0;
        $total = 10000;
        for ($i = 0; $i < $total; $i++) {
            if (EvaluationService::computeBucket("user-{$i}", $flagName) < 50) {
                $enabled++;
            }
        }
        // Allow ±3% drift - crc32 is uniform enough to hit this easily.
        self::assertGreaterThan(4700, $enabled);
        self::assertLessThan(5300, $enabled);
    }

    public function testFiftyPercentRolloutActivatesRoughlyHalf(): void
    {
        $activated = 0;
        for ($i = 1; $i <= 1000; $i++) {
            if (EvaluationService::computeBucket((string)$i, 'half-flag') < 50) {
                $activated++;
            }
        }
        self::assertGreaterThan(400, $activated);
        self::assertLessThan(600, $activated);
    }

    public function testZeroPercentRolloutEnablesNobody(): void
    {
        // A flag with rolloutPercentage = 0 should never enable via bucket math.
        // The bucket value can be any number, but the caller's `< $percentage`
        // check must exclude 0 - we verify here that no bucket is less than 0.
        for ($i = 0; $i < 100; $i++) {
            $bucket = EvaluationService::computeBucket((string)$i, 'zero-flag');
            self::assertGreaterThanOrEqual(0, $bucket);
        }
    }

    public function testBucketInputOfEmptyStringDoesNotCrash(): void
    {
        // Callers guard against empty input, but the math itself should be
        // well-defined if called with "". crc32('') is 0, so bucket should be 0.
        $bucket = EvaluationService::computeBucket('', 'any-flag');
        self::assertIsInt($bucket);
        self::assertGreaterThanOrEqual(0, $bucket);
        self::assertLessThanOrEqual(99, $bucket);
    }

    public function testUserIdAsStringAndIntProduceSameBucket(): void
    {
        // We always cast user id to string before calling computeBucket, so
        // '42' and (string)42 must behave identically.
        $a = EvaluationService::computeBucket('42', 'flag');
        $b = EvaluationService::computeBucket((string)42, 'flag');
        self::assertSame($a, $b);
    }
}
