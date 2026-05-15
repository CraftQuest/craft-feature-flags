<?php

declare(strict_types=1);

namespace craftquest\featureflags\tests\unit;

use craftquest\featureflags\enums\FlagType;
use PHPUnit\Framework\TestCase;

/**
 * Guards the FlagType enum values. Dropping or renaming a case would silently
 * invalidate existing DB rows, so these tests lock the current surface.
 */
final class FlagTypeTest extends TestCase
{
    public function testAllExpectedCasesExist(): void
    {
        $values = array_map(fn(FlagType $t) => $t->value, FlagType::cases());
        sort($values);
        self::assertSame(
            ['experiment', 'ops', 'permission', 'release'],
            $values
        );
    }

    public function testReleaseValue(): void
    {
        self::assertSame('release', FlagType::Release->value);
    }

    public function testExperimentValue(): void
    {
        self::assertSame('experiment', FlagType::Experiment->value);
    }

    public function testOpsValue(): void
    {
        self::assertSame('ops', FlagType::Ops->value);
    }

    public function testPermissionValue(): void
    {
        self::assertSame('permission', FlagType::Permission->value);
    }

    public function testFromValidValue(): void
    {
        self::assertSame(FlagType::Release, FlagType::from('release'));
    }

    public function testTryFromInvalidValueReturnsNull(): void
    {
        self::assertNull(FlagType::tryFrom('not-a-type'));
    }
}
