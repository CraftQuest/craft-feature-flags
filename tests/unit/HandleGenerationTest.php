<?php

declare(strict_types=1);

namespace craftquest\featureflags\tests\unit;

use craftquest\featureflags\services\FlagService;
use PHPUnit\Framework\TestCase;

/**
 * Tests FlagService::generateHandle() — server-side kebab-case generation
 * from a human-readable flag name.
 */
final class HandleGenerationTest extends TestCase
{
    private FlagService $service;

    protected function setUp(): void
    {
        $this->service = new FlagService();
    }

    /**
     * @dataProvider handleCases
     */
    public function testGenerateHandle(string $input, string $expected): void
    {
        self::assertSame($expected, $this->service->generateHandle($input));
    }

    public static function handleCases(): array
    {
        return [
            'normal words'      => ['New Checkout Flow', 'new-checkout-flow'],
            'already kebab'     => ['checkout-v2', 'checkout-v2'],
            'mixed case'        => ['MyFeatureFlag', 'myfeatureflag'],
            'special chars'     => ['Hello, World! (v2)', 'hello-world-v2'],
            'extra spaces'      => ['  lots   of   spaces  ', 'lots-of-spaces'],
        ];
    }
}
