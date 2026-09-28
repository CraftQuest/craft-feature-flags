<?php

declare(strict_types=1);

namespace craftquest\featureflags\tests\unit;

use craftquest\featureflags\services\EvaluationService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the schedule window decision (EvaluationService::isOutsideSchedule).
 *
 * Model: a flag is off before its optional start date and off once its optional
 * expiration date has passed. Both bounds are independent; a flag with neither
 * is never outside the window.
 *
 * isOutsideSchedule() returns true when the flag should be blocked (off).
 */
final class ScheduleWindowTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-28 12:00:00', new \DateTimeZone('UTC'));
    }

    public function testNoBoundsIsNeverOutside(): void
    {
        self::assertFalse(EvaluationService::isOutsideSchedule(null, null, $this->now));
    }

    // --- start date only ---

    public function testBeforeStartIsOutside(): void
    {
        $startsAt = $this->now->modify('+1 hour');
        self::assertTrue(EvaluationService::isOutsideSchedule($startsAt, null, $this->now));
    }

    public function testAtStartIsInside(): void
    {
        self::assertFalse(EvaluationService::isOutsideSchedule($this->now, null, $this->now));
    }

    public function testAfterStartIsInside(): void
    {
        $startsAt = $this->now->modify('-1 hour');
        self::assertFalse(EvaluationService::isOutsideSchedule($startsAt, null, $this->now));
    }

    // --- expiration only (pre-existing behavior) ---

    public function testBeforeExpiryIsInside(): void
    {
        $expiresAt = $this->now->modify('+1 hour');
        self::assertFalse(EvaluationService::isOutsideSchedule(null, $expiresAt, $this->now));
    }

    public function testAtExpiryIsStillInside(): void
    {
        // Matches the original isInThePast() semantics: expired only once strictly past.
        self::assertFalse(EvaluationService::isOutsideSchedule(null, $this->now, $this->now));
    }

    public function testAfterExpiryIsOutside(): void
    {
        $expiresAt = $this->now->modify('-1 second');
        self::assertTrue(EvaluationService::isOutsideSchedule(null, $expiresAt, $this->now));
    }

    // --- both bounds ---

    public function testInsideWindowIsInside(): void
    {
        $startsAt = $this->now->modify('-1 day');
        $expiresAt = $this->now->modify('+1 day');
        self::assertFalse(EvaluationService::isOutsideSchedule($startsAt, $expiresAt, $this->now));
    }

    public function testBeforeWindowIsOutside(): void
    {
        $startsAt = $this->now->modify('+1 day');
        $expiresAt = $this->now->modify('+2 days');
        self::assertTrue(EvaluationService::isOutsideSchedule($startsAt, $expiresAt, $this->now));
    }

    public function testAfterWindowIsOutside(): void
    {
        $startsAt = $this->now->modify('-2 days');
        $expiresAt = $this->now->modify('-1 day');
        self::assertTrue(EvaluationService::isOutsideSchedule($startsAt, $expiresAt, $this->now));
    }

    public function testComparesInstantsAcrossTimezones(): void
    {
        // 13:00 in UTC+2 is 11:00 UTC, which is before "now" (12:00 UTC): started.
        $startsAt = new DateTimeImmutable('2026-09-28 13:00:00', new \DateTimeZone('+02:00'));
        self::assertFalse(EvaluationService::isOutsideSchedule($startsAt, null, $this->now));

        // 15:00 in UTC+2 is 13:00 UTC, which is after "now": not started.
        $startsAt = new DateTimeImmutable('2026-09-28 15:00:00', new \DateTimeZone('+02:00'));
        self::assertTrue(EvaluationService::isOutsideSchedule($startsAt, null, $this->now));
    }
}
