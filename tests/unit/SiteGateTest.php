<?php

declare(strict_types=1);

namespace craftquest\featureflags\tests\unit;

use craftquest\featureflags\services\EvaluationService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the per-site gate decision (EvaluationService::isGatedOff).
 *
 * Model: a flag with no per-site settings applies to all sites ("all sites" mode).
 * A flag with per-site settings is in "specific sites" mode and is enabled only on
 * sites explicitly turned on — unlisted sites, sites added later, and sites that can't
 * be resolved (console/queue, invalid id) are off. Sites are an explicit opt-in.
 *
 * isGatedOff() returns true when the flag should be blocked (off) for the context.
 */
final class SiteGateTest extends TestCase
{
    public function testSingleSiteInstallIsNeverGated(): void
    {
        self::assertFalse(EvaluationService::isGatedOff(false, null, []));
        self::assertFalse(EvaluationService::isGatedOff(false, 1, [1 => false]));
    }

    // --- "all sites" mode: no per-site settings => applies everywhere ---

    public function testAllSitesFlagIsNotGatedOnAnyResolvedSite(): void
    {
        self::assertFalse(EvaluationService::isGatedOff(true, 1, []));
        self::assertFalse(EvaluationService::isGatedOff(true, 99, []));
    }

    public function testAllSitesFlagIsNotGatedInConsoleOrWithInvalidSite(): void
    {
        // A global flag has no per-site answer to get wrong, so it stays on even when
        // no site can be resolved (console/queue) or a bogus siteId was requested.
        self::assertFalse(EvaluationService::isGatedOff(true, null, []));
    }

    // --- "specific sites" mode: enabled only where explicitly turned on ---

    public function testSpecificSiteExplicitlyOnIsNotGated(): void
    {
        self::assertFalse(EvaluationService::isGatedOff(true, 1, [1 => true, 2 => false]));
    }

    public function testSpecificSiteExplicitlyOffIsGated(): void
    {
        self::assertTrue(EvaluationService::isGatedOff(true, 2, [1 => true, 2 => false]));
    }

    public function testUnlistedSiteIsGatedOff(): void
    {
        // The whole point: a scoped flag is OFF on sites it doesn't explicitly list,
        // including any site added after the flag was scoped. No implicit opt-in.
        self::assertTrue(EvaluationService::isGatedOff(true, 3, [1 => true, 2 => true]));
    }

    public function testScopedFlagWithNoResolvableSiteFailsClosed(): void
    {
        // Console/queue without a siteId, or an invalid explicit siteId, for a scoped flag.
        self::assertTrue(EvaluationService::isGatedOff(true, null, [1 => true]));
        self::assertTrue(EvaluationService::isGatedOff(true, null, [1 => false]));
    }

    public function testScopedFlagWithAllSitesDisabledIsGatedEverywhere(): void
    {
        // "Specific sites" chosen but nothing enabled => off on every site.
        self::assertTrue(EvaluationService::isGatedOff(true, 1, [1 => false, 2 => false]));
        self::assertTrue(EvaluationService::isGatedOff(true, 2, [1 => false, 2 => false]));
    }
}
