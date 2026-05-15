<?php

declare(strict_types=1);

namespace craftquest\featureflags\tests\unit;

use craftquest\featureflags\services\EvaluationService;
use PHPUnit\Framework\TestCase;

/**
 * Validates that percentage rollouts produce the expected distribution
 * across a population of user IDs and anonymous visitor UUIDs.
 */
final class RolloutDistributionTest extends TestCase
{
    private const POPULATION = 10000;
    private const TOLERANCE = 0.03; // ±3%

    /**
     * @dataProvider percentageProvider
     */
    public function testRolloutDistributionWithUserIds(int $percentage): void
    {
        $enabled = 0;
        for ($i = 1; $i <= self::POPULATION; $i++) {
            if (EvaluationService::computeBucket((string)$i, 'rollout-test') < $percentage) {
                $enabled++;
            }
        }

        $actual = $enabled / self::POPULATION;
        $expected = $percentage / 100;

        self::assertEqualsWithDelta(
            $expected,
            $actual,
            self::TOLERANCE,
            "Expected ~{$percentage}% rollout for user IDs, got " . round($actual * 100, 1) . '%'
        );
    }

    /**
     * @dataProvider percentageProvider
     */
    public function testRolloutDistributionWithUuids(int $percentage): void
    {
        $enabled = 0;
        for ($i = 0; $i < self::POPULATION; $i++) {
            $hash = crc32("visitor-{$i}");
            $uuid = sprintf(
                '%08x-%04x-4%03x-%04x-%012x',
                $hash,
                ($i >> 4) & 0xFFFF,
                $i & 0x0FFF,
                0x8000 | ($i & 0x3FFF),
                abs($hash) & 0xFFFFFFFF
            );
            if (EvaluationService::computeBucket($uuid, 'rollout-uuid-test') < $percentage) {
                $enabled++;
            }
        }

        $actual = $enabled / self::POPULATION;
        $expected = $percentage / 100;

        self::assertEqualsWithDelta(
            $expected,
            $actual,
            self::TOLERANCE,
            "Expected ~{$percentage}% rollout for UUIDs, got " . round($actual * 100, 1) . '%'
        );
    }

    public static function percentageProvider(): array
    {
        return [
            '1%'   => [1],
            '10%'  => [10],
            '25%'  => [25],
            '50%'  => [50],
            '75%'  => [75],
            '90%'  => [90],
            '99%'  => [99],
        ];
    }

    public function testZeroPercentEnablesNobody(): void
    {
        for ($i = 0; $i < 1000; $i++) {
            $bucket = EvaluationService::computeBucket((string)$i, 'zero-rollout');
            self::assertGreaterThanOrEqual(0, $bucket);
            // bucket < 0 is impossible, so nobody passes the `< 0` check
        }
    }

    public function testHundredPercentEnablesEverybody(): void
    {
        for ($i = 0; $i < 1000; $i++) {
            $bucket = EvaluationService::computeBucket((string)$i, 'full-rollout');
            self::assertLessThan(100, $bucket, "Bucket should always be < 100");
        }
    }

    public function testUserIdAndUuidNeverShareBucketNamespace(): void
    {
        // Verify that a user ID and a UUID hitting the same bucket is not
        // systematically correlated — they should distribute independently.
        $flag = 'cross-check';
        $userBuckets = [];
        $uuidBuckets = [];

        for ($i = 1; $i <= 1000; $i++) {
            $userBuckets[] = EvaluationService::computeBucket((string)$i, $flag);
            $uuid = sprintf('%08x-%04x-4000-8000-%012x', $i, $i, $i);
            $uuidBuckets[] = EvaluationService::computeBucket($uuid, $flag);
        }

        // The average bucket for each population should both be near 49.5
        // but they should NOT be identical arrays (different inputs = different hashes)
        self::assertNotSame($userBuckets, $uuidBuckets);

        $userAvg = array_sum($userBuckets) / count($userBuckets);
        $uuidAvg = array_sum($uuidBuckets) / count($uuidBuckets);

        // Both averages should be roughly centered
        self::assertEqualsWithDelta(49.5, $userAvg, 5.0);
        self::assertEqualsWithDelta(49.5, $uuidAvg, 5.0);
    }

    public function testBucketStabilityAcrossRepeatedCalls(): void
    {
        // Simulates what happens in a real request: the same visitor
        // evaluates multiple flags, and each flag should return the
        // same result every time.
        $inputs = ['42', 'c9bf9e57-1685-4c89-bafb-ff5af830be8a', 'session-abc-123'];
        $flags = ['feature-a', 'feature-b', 'feature-c'];

        foreach ($inputs as $input) {
            foreach ($flags as $flag) {
                $first = EvaluationService::computeBucket($input, $flag);
                for ($i = 0; $i < 10; $i++) {
                    self::assertSame(
                        $first,
                        EvaluationService::computeBucket($input, $flag),
                        "Bucket changed on repeated call for input={$input}, flag={$flag}"
                    );
                }
            }
        }
    }
}
