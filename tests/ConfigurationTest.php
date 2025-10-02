<?php

declare(strict_types=1);

namespace Swotto\Tests;

use PHPUnit\Framework\TestCase;
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

    public function testUpdateConfiguration(): void
    {
        $config = new Configuration(['url' => 'https://api.example.com']);
        $updatedConfig = $config->update(['key' => 'test_key']);

        $this->assertEquals('test_key', $updatedConfig->get('key'));
        $this->assertEquals('https://api.example.com', $updatedConfig->get('url'));
        $this->assertNull($config->get('key')); // Original unchanged
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

    public function testGetHeaders(): void
    {
        $config = new Configuration([
            'url' => 'https://api.example.com',
            'key' => 'test_key',
            'access_token' => 'token123',
            'session_id' => 'sess456',
            'language' => 'it',
            'accept' => 'application/xml',
        ]);

        $headers = $config->getHeaders();

        $this->assertEquals('application/xml', $headers['Accept']);
        $this->assertEquals('it', $headers['Accept-Language']);
        $this->assertEquals('Bearer token123', $headers['Authorization']);
        $this->assertEquals('test_key', $headers['x-devapp']);
        $this->assertEquals('sess456', $headers['x-sid']);
    }

    public function testGetHeadersWithoutOptionalValues(): void
    {
        $config = new Configuration(['url' => 'https://api.example.com']);

        $headers = $config->getHeaders();

        $this->assertEquals('application/json', $headers['Accept']);
        $this->assertEquals('en', $headers['Accept-Language']);
        $this->assertArrayNotHasKey('Authorization', $headers);
        $this->assertArrayNotHasKey('x-devapp', $headers);
        $this->assertArrayNotHasKey('x-sid', $headers);
    }

    public function testDetectClientUserAgent(): void
    {
        $config = new Configuration([
            'url' => 'https://api.example.com',
            'client_user_agent' => 'TestAgent/1.0',
        ]);

        $this->assertEquals('TestAgent/1.0', $config->detectClientUserAgent());
    }

    public function testDetectClientIp(): void
    {
        $config = new Configuration([
            'url' => 'https://api.example.com',
            'client_ip' => '192.168.1.1',
        ]);

        $this->assertEquals('192.168.1.1', $config->detectClientIp());
    }
}
