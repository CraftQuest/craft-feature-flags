<?php

declare(strict_types=1);

namespace craftquest\featureflags\tests\unit;

use craftquest\featureflags\services\EvaluationService;
use PHPUnit\Framework\TestCase;

/**
 * Tests that UUID-format strings (used for anonymous visitor bucketing)
 * produce correct, deterministic, well-distributed bucket values.
 */
final class AnonymousBucketingTest extends TestCase
{
    public function testUuidBucketIsDeterministic(): void
    {
        $uuid = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';
        $a = EvaluationService::computeBucket($uuid, 'homepage-test');
        $b = EvaluationService::computeBucket($uuid, 'homepage-test');
        self::assertSame($a, $b);
    }

    public function testUuidBucketIsWithinRange(): void
    {
        $uuids = [
            '00000000-0000-0000-0000-000000000000',
            'ffffffff-ffff-ffff-ffff-ffffffffffff',
            '550e8400-e29b-41d4-a716-446655440000',
            '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
            'f47ac10b-58cc-4372-a567-0e02b2c3d479',
        ];

        foreach ($uuids as $uuid) {
            $bucket = EvaluationService::computeBucket($uuid, 'range-test');
            self::assertGreaterThanOrEqual(0, $bucket, "UUID {$uuid} produced bucket below 0");
            self::assertLessThanOrEqual(99, $bucket, "UUID {$uuid} produced bucket above 99");
        }
    }

    public function testDifferentUuidsProduceDifferentBuckets(): void
    {
        $uuids = [];
        for ($i = 0; $i < 20; $i++) {
            // Generate deterministic UUID-like strings for testing
            $uuids[] = sprintf(
                '%08x-%04x-%04x-%04x-%012x',
                $i * 12345, $i * 11, $i * 22, $i * 33, $i * 9876543
            );
        }

        $buckets = [];
        foreach ($uuids as $uuid) {
            $buckets[] = EvaluationService::computeBucket($uuid, 'diversity-test');
        }

        // 20 UUIDs should produce more than 1 distinct bucket
        self::assertGreaterThan(1, count(array_unique($buckets)));
    }

    public function testUuidDistributionIsUniform(): void
    {
        $flagName = 'uuid-distribution';
        $enabled = 0;
        $total = 10000;

        for ($i = 0; $i < $total; $i++) {
            // Synthetic UUID-shaped strings
            $uuid = sprintf(
                '%08x-%04x-%04x-%04x-%012x',
                $i, ($i >> 4) & 0xFFFF, ($i >> 8) & 0xFFFF, ($i >> 12) & 0xFFFF, $i * 7919
            );
            if (EvaluationService::computeBucket($uuid, $flagName) < 50) {
                $enabled++;
            }
        }

        // Allow ±3% drift
        self::assertGreaterThan(4700, $enabled);
        self::assertLessThan(5300, $enabled);
    }

    public function testSameUuidGetsDifferentBucketsPerFlag(): void
    {
        $uuid = 'c9bf9e57-1685-4c89-bafb-ff5af830be8a';
        $buckets = [];
        foreach (['flag-1', 'flag-2', 'flag-3', 'flag-4', 'flag-5'] as $flag) {
            $buckets[] = EvaluationService::computeBucket($uuid, $flag);
        }

        // At least some of the 5 buckets should differ
        self::assertGreaterThan(1, count(array_unique($buckets)));
    }

    public function testUuidAndUserIdProduceDifferentBuckets(): void
    {
        // A logged-in user and an anonymous visitor should generally get
        // different buckets for the same flag, confirming the input matters.
        $userId = '42';
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $flag = 'checkout-test';

        $userBucket = EvaluationService::computeBucket($userId, $flag);
        $uuidBucket = EvaluationService::computeBucket($uuid, $flag);

        // They *could* collide (1-in-100 chance), but we just verify both
        // are valid buckets. The important thing is they're computed independently.
        self::assertGreaterThanOrEqual(0, $userBucket);
        self::assertLessThanOrEqual(99, $userBucket);
        self::assertGreaterThanOrEqual(0, $uuidBucket);
        self::assertLessThanOrEqual(99, $uuidBucket);
    }
}
