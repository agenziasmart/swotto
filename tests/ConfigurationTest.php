<?php

declare(strict_types=1);

namespace Swotto\Tests;

use PHPUnit\Framework\TestCase;
use Swotto\Auth\InMemoryTokenCache;
use Swotto\Config\Configuration;
use Swotto\Exception\ConfigurationException;

class ConfigurationTest extends TestCase
{
    public function testValidConfiguration(): void
    {
        $config = new Configuration(['url' => 'https://api.example.com']);

        $this->assertEquals('https://api.example.com', $config->getBaseUrl());
        $this->assertEquals('https://api.example.com', $config->get('url'));
    }

    public function testMissingRequiredKey(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("Configuration key 'url' is required");

        new Configuration([]);
    }

    public function testInvalidConfigurationKey(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("Invalid configuration key: 'invalid_key'");

        new Configuration([
            'url' => 'https://api.example.com',
            'invalid_key' => 'value',
        ]);
    }

    public function testOldAccessTokenKeyIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("Invalid configuration key: 'access_token'");

        new Configuration([
            'url' => 'https://api.example.com',
            'access_token' => 'token123',
        ]);
    }

    public function testBearerTokenIsAccepted(): void
    {
        $config = new Configuration([
            'url' => 'https://api.example.com',
            'bearer_token' => 'token123',
        ]);

        $this->assertEquals('token123', $config->get('bearer_token'));
    }

    public function testInvalidVerifySsl(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('verify_ssl must be boolean');

        new Configuration([
            'url' => 'https://api.example.com',
            'verify_ssl' => 'not_boolean',
        ]);
    }

    public function testGetWithDefault(): void
    {
        $config = new Configuration(['url' => 'https://api.example.com']);

        $this->assertEquals('default_value', $config->get('non_existent', 'default_value'));
        $this->assertNull($config->get('non_existent'));
    }

    public function testBaseUrlWithTrailingSlash(): void
    {
        $config = new Configuration(['url' => 'https://api.example.com/']);

        $this->assertEquals('https://api.example.com', $config->getBaseUrl());
    }

    public function testToArray(): void
    {
        $configData = [
            'url' => 'https://api.example.com',
            'key' => 'test_key',
            'language' => 'it',
        ];

        $config = new Configuration($configData);

        $this->assertEquals($configData, $config->toArray());
    }

    public function testGetHeadersTransportOnly(): void
    {
        $config = new Configuration([
            'url' => 'https://api.example.com',
            'key' => 'test_key',
            'bearer_token' => 'token123',
            'session_id' => 'sess456',
            'language' => 'it',
        ]);

        $headers = $config->getHeaders();

        // Only transport headers
        $this->assertEquals('application/json', $headers['Accept']);
        $this->assertEquals('test_key', $headers['x-devapp']);

        // Context headers are NOT in getHeaders() anymore
        $this->assertArrayNotHasKey('Authorization', $headers);
        $this->assertArrayNotHasKey('Accept-Language', $headers);
        $this->assertArrayNotHasKey('x-sid', $headers);
    }

    public function testGetHeadersWithoutOptionalValues(): void
    {
        $config = new Configuration(['url' => 'https://api.example.com']);

        $headers = $config->getHeaders();

        $this->assertEquals('application/json', $headers['Accept']);
        $this->assertArrayNotHasKey('x-devapp', $headers);
    }

    public function testConfigurationMultipleTrailingSlashes(): void
    {
        $config = new Configuration(['url' => 'https://api.example.com///']);

        $this->assertEquals('https://api.example.com', $config->getBaseUrl());
    }

    public function testTimeoutValidation(): void
    {
        $config = new Configuration([
            'url' => 'https://api.example.com',
            'timeout' => 30,
        ]);

        $this->assertEquals(30, $config->get('timeout'));
    }

    public function testInvalidTimeout(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('timeout must be a positive integer');

        new Configuration([
            'url' => 'https://api.example.com',
            'timeout' => 0,
        ]);
    }

    public function testAppNameAndVersionAreAccepted(): void
    {
        $config = new Configuration([
            'url' => 'https://api.example.com',
            'app_name' => 'MyApp',
            'app_version' => '1.0.0',
        ]);

        $this->assertEquals('MyApp', $config->get('app_name'));
        $this->assertEquals('1.0.0', $config->get('app_version'));
    }

    public function testClientIdWithKeyIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Choose one authentication mode: key, bearer_token or client_id');

        new Configuration([
            'url' => 'https://api.example.com',
            'key' => 'devapp-token',
            'client_id' => 'catalog-client',
            'client_secret' => 'catalog-secret',
        ]);
    }

    public function testClientIdWithBearerTokenIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Choose one authentication mode: key, bearer_token or client_id');

        new Configuration([
            'url' => 'https://api.example.com',
            'bearer_token' => 'account-token',
            'client_id' => 'catalog-client',
            'client_secret' => 'catalog-secret',
        ]);
    }

    public function testClientIdRequiresClientSecret(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('client_id requires a non-empty client_secret');

        new Configuration([
            'url' => 'https://api.example.com',
            'client_id' => 'catalog-client',
        ]);
    }

    public function testScopeMustBeString(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('scope must be a string, array given');

        new Configuration([
            'url' => 'https://api.example.com',
            'client_id' => 'catalog-client',
            'client_secret' => 'catalog-secret',
            'scope' => ['product.readonly'],
        ]);
    }

    public function testTokenCacheMustImplementTheInterface(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('token_cache must implement Swotto\\Contract\\TokenCacheInterface, stdClass given');

        new Configuration([
            'url' => 'https://api.example.com',
            'client_id' => 'catalog-client',
            'client_secret' => 'catalog-secret',
            'token_cache' => new \stdClass(),
        ]);
    }

    public function testClientCredentialsModeIsAccepted(): void
    {
        $cache = new InMemoryTokenCache();
        $config = new Configuration([
            'url' => 'https://api.example.com',
            'client_id' => 'catalog-client',
            'client_secret' => 'catalog-secret',
            'scope' => 'category.readonly product.readonly',
            'token_cache' => $cache,
        ]);

        $this->assertSame('catalog-client', $config->get('client_id'));
        $this->assertSame($cache, $config->get('token_cache'));
    }

    public function testKeyWithBearerTokenIsStillAccepted(): void
    {
        $config = new Configuration([
            'url' => 'https://api.example.com',
            'key' => 'devapp-token',
            'bearer_token' => 'account-token',
        ]);

        $this->assertSame('account-token', $config->get('bearer_token'));
    }

    public function testClientSecretWithoutClientIdIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('client_secret requires client_id');

        new Configuration([
            'url' => 'https://api.example.com',
            'bearer_token' => 'account-token',
            'client_secret' => 'catalog-secret',
        ]);
    }
}
