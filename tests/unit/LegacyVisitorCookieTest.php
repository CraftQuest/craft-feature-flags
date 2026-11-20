<?php

declare(strict_types=1);

namespace craftquest\featureflags\tests\unit;

use craftquest\featureflags\services\EvaluationService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for reading Craft 5 (Yii-signed) visitor cookies on Craft 6
 * (EvaluationService::legacyVisitorId).
 *
 * Craft 5 stored `<hex HMAC-SHA256><serialize([$name, $uuid])>`. Laravel can't decrypt that,
 * so the plugin reads the raw value once to keep the visitor in the same rollout buckets.
 */
final class LegacyVisitorCookieTest extends TestCase
{
    private const NAME = '_ff_vid';
    private const UUID = 'c2988ac7-b0fc-45b6-8c79-0bbd27eb5a18';
    private const KEY = 'craft-5-security-key';

    private static function legacyValue(string $name = self::NAME, string $uuid = self::UUID, string $key = self::KEY): string
    {
        $data = serialize([$name, $uuid]);

        return hash_hmac('sha256', $data, $key) . $data;
    }

    public function testReadsUuidWhenSignatureMatchesKey(): void
    {
        $this->assertSame(self::UUID, EvaluationService::legacyVisitorId(self::legacyValue(), self::NAME, self::KEY));
    }

    public function testReadsUuidWithoutKey(): void
    {
        // Craft's upgrade removes the Craft 5 key, so the signature usually can't be checked.
        $this->assertSame(self::UUID, EvaluationService::legacyVisitorId(self::legacyValue(), self::NAME, null));
        $this->assertSame(self::UUID, EvaluationService::legacyVisitorId(self::legacyValue(), self::NAME, ''));
    }

    public function testRejectsWrongSignatureWhenKeyIsKnown(): void
    {
        $this->assertNull(EvaluationService::legacyVisitorId(self::legacyValue(key: 'some-other-key'), self::NAME, self::KEY));
    }

    public function testRejectsTamperedUuidWhenKeyIsKnown(): void
    {
        $value = str_replace(self::UUID, '00000000-0000-0000-0000-000000000000', self::legacyValue());
        $this->assertNull(EvaluationService::legacyVisitorId($value, self::NAME, self::KEY));
    }

    public function testRejectsCookieForAnotherName(): void
    {
        $this->assertNull(EvaluationService::legacyVisitorId(self::legacyValue(name: 'other'), self::NAME, null));
    }

    public function testRejectsNonUuidValue(): void
    {
        $this->assertNull(EvaluationService::legacyVisitorId(self::legacyValue(uuid: 'not-a-uuid-value-at-all-0123456789ab'), self::NAME, null));
    }

    public function testRejectsGarbageAndOtherFormats(): void
    {
        foreach ([null, '', self::UUID, 'abc', str_repeat('a', 64), self::legacyValue() . 'x', 'eyJpdiI6IjEyMyJ9'] as $raw) {
            $this->assertNull(EvaluationService::legacyVisitorId($raw, self::NAME, null), var_export($raw, true));
        }
    }

    public function testRejectsSerializedObjects(): void
    {
        $data = 'a:2:{i:0;s:7:"_ff_vid";i:1;O:8:"stdClass":0:{}}';
        $this->assertNull(EvaluationService::legacyVisitorId(str_repeat('0', 64) . $data, self::NAME, null));
    }

    public function testMatchesRealCraft5Cookie(): void
    {
        // A value captured from a Craft 5 site (signature from an unrelated test key).
        $raw = '8d86e502dcf25bc74090bf5c357023eca6ee441da632d072516675a02d62bd8d'
            . 'a:2:{i:0;s:7:"_ff_vid";i:1;s:36:"c2988ac7-b0fc-45b6-8c79-0bbd27eb5a18";}';
        $this->assertSame(self::UUID, EvaluationService::legacyVisitorId($raw, self::NAME, null));
    }
}
