<?php

declare(strict_types=1);

namespace craftquest\featureflags\tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests the flag handle validation regex in isolation. The regex lives in
 * Flag::defineRules() and gates every flag insert - changes must be deliberate.
 *
 * Rule: must start with a lowercase letter, and thereafter only lowercase
 * letters, digits, and hyphens are allowed.
 */
final class FlagHandleRegexTest extends TestCase
{
    private const PATTERN = '/^[a-z][a-z0-9\-]*$/';

    /**
     * @dataProvider validHandles
     */
    public function testValidHandlesPass(string $handle): void
    {
        self::assertSame(1, preg_match(self::PATTERN, $handle), "Expected '{$handle}' to be valid");
    }

    /**
     * @dataProvider invalidHandles
     */
    public function testInvalidHandlesFail(string $handle): void
    {
        self::assertSame(0, preg_match(self::PATTERN, $handle), "Expected '{$handle}' to be invalid");
    }

    public static function validHandles(): array
    {
        return [
            'simple lowercase'         => ['checkout'],
            'with digits'              => ['feature-v2'],
            'with hyphens'             => ['new-checkout-flow'],
            'single letter'            => ['a'],
            'digits after letter'      => ['a123'],
            'multiple hyphens'         => ['long-name-with-many-parts'],
            'trailing digit'           => ['flag-2024'],
        ];
    }

    public static function invalidHandles(): array
    {
        return [
            'starts with digit'        => ['2-flag'],
            'starts with hyphen'       => ['-flag'],
            'starts with uppercase'    => ['Flag'],
            'contains uppercase'       => ['new-Checkout'],
            'contains underscore'      => ['new_checkout'],
            'contains space'           => ['new checkout'],
            'contains dot'             => ['new.checkout'],
            'contains slash'           => ['feature/flag'],
            'empty string'             => [''],
            'single hyphen'            => ['-'],
            'single digit'             => ['1'],
            'leading whitespace'       => [' flag'],
            'trailing whitespace'      => ['flag '],
            'sql injection attempt'    => ["flag'; DROP TABLE--"],
            'xss attempt'              => ['<script>'],
        ];
    }
}
