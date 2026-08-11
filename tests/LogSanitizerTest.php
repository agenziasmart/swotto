<?php

declare(strict_types=1);

namespace Swotto\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swotto\Http\LogSanitizer;

/** The request metadata boundary is shared by the base client and retry decorator. */
final class LogSanitizerTest extends TestCase
{
    #[DataProvider('httpMethodProvider')]
    public function testHttpMethodIsUppercaseAndAllowlisted(string $method, string $expected): void
    {
        self::assertSame($expected, LogSanitizer::httpMethod($method));
    }

    /** @return iterable<string, array{string, string}> */
    public static function httpMethodProvider(): iterable
    {
        yield 'normalizes case' => ['gEt', 'GET'];
        yield 'keeps an allowlisted method' => ['PATCH', 'PATCH'];
        yield 'rejects CRLF injection' => ["GET\r\nSENTINEL_METHOD", 'UNKNOWN'];
        yield 'rejects extension method' => ['SENTINEL_EXTENSION', 'UNKNOWN'];
        yield 'rejects empty method' => ['', 'UNKNOWN'];
    }

    public function testUriRemovesSensitiveAndUnsafeMetadataWithoutSplittingUtf8(): void
    {
        $uri = 'https://user:SENTINEL_USERINFO@api.example.com/safe'
            . "\r\n\x00\u{200B}\u{2028}\u{2029}\xC3\x28"
            . str_repeat('à', 300)
            . '?token=SENTINEL_QUERY#SENTINEL_FRAGMENT';

        $safe = LogSanitizer::uri($uri);

        self::assertStringStartsWith('https://api.example.com/safe', $safe);
        self::assertStringNotContainsString('SENTINEL_', $safe);
        self::assertTrue(mb_check_encoding($safe, 'UTF-8'));
        self::assertLessThanOrEqual(512, strlen($safe));
        self::assertDoesNotMatchRegularExpression('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $safe);
    }
}
