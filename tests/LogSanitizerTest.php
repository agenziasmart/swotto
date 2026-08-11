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

    #[DataProvider('utf8SubstituteProvider')]
    public function testUserInfoIsRemovedBeforeGlobalUtf8SubstitutionCanChangeParsing(int $substitute): void
    {
        $previousSubstitute = mb_substitute_character();
        mb_substitute_character($substitute);

        try {
            $safe = LogSanitizer::uri(
                "https://user:SENTINEL_BEFORE\xFFSENTINEL_AFTER@api.example.com/auth"
                . '?token=SENTINEL_QUERY'
            );
        } finally {
            mb_substitute_character($previousSubstitute);
        }

        self::assertSame('https://api.example.com/auth', $safe);
        self::assertStringNotContainsString('SENTINEL_', $safe);
        self::assertSame($previousSubstitute, mb_substitute_character());
    }

    /** @return iterable<string, array{int}> */
    public static function utf8SubstituteProvider(): iterable
    {
        yield 'slash' => [47];
        yield 'question mark' => [63];
    }

    #[DataProvider('utf8SubstituteProvider')]
    public function testMalformedSchemeOrDelimiterCannotPreserveUserInfo(int $substitute): void
    {
        $previousSubstitute = mb_substitute_character();
        mb_substitute_character($substitute);
        $safeUris = [];

        try {
            foreach ([
                "ht\xFFtps://user:SENTINEL_SCHEME@api.example.com/auth?token=SENTINEL_QUERY",
                "https\xFF://user:SENTINEL_BEFORE_DELIMITER@api.example.com/auth",
                "https:\xFF//user:SENTINEL_IN_DELIMITER@api.example.com/auth",
                "https:/\xFFuser:SENTINEL_MANUFACTURED_AUTHORITY@api.example.com/auth",
            ] as $uri) {
                $safeUris[] = LogSanitizer::uri($uri);
            }
        } finally {
            mb_substitute_character($previousSubstitute);
        }

        foreach ($safeUris as $safeUri) {
            self::assertStringNotContainsString('SENTINEL_', $safeUri);
            self::assertStringContainsString('api.example.com/auth', $safeUri);
            self::assertTrue(mb_check_encoding($safeUri, 'UTF-8'));
        }
        self::assertSame($previousSubstitute, mb_substitute_character());
    }
}
