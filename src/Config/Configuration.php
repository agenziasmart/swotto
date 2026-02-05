<?php

declare(strict_types=1);

namespace Swotto\Config;

use Swotto\Exception\ConfigurationException;

/**
 * Configuration.
 *
 * Manages and validates Swotto Client configuration.
 * Immutable: no update() method. Context options flow via defaultOptions + merge.
 */
final class Configuration
{
    /**
     * @var array<int, string> Required configuration keys
     */
    private const REQUIRED_CONFIG = ['url'];

    /**
     * @var array<int, string> Allowed configuration keys
     */
    private const ALLOWED_CONFIG = [
        // Transport
        'url',
        'key',
        'verify_ssl',
        'timeout',
        // Context (extracted as defaultOptions in Client)
        'bearer_token',
        'language',
        'session_id',
        'client_ip',
        'client_user_agent',
        // Retry
        'retry_enabled',
        'retry_max_attempts',
        'retry_initial_delay_ms',
        'retry_max_delay_ms',
        'retry_multiplier',
        'retry_jitter',
    ];

    /**
     * @var array<string, mixed> Configuration values
     */
    private readonly array $config;

    /**
     * Constructor.
     *
     * @param array<string, mixed> $config Configuration options
     *
     * @throws ConfigurationException On invalid configuration
     */
    public function __construct(array $config = [])
    {
        $this->validateConfig($config);
        $this->config = $config;
    }

    /**
     * Validate configuration options.
     *
     * @param array<string, mixed> $config Configuration options to validate
     * @return void
     *
     * @throws ConfigurationException On invalid configuration
     */
    private function validateConfig(array $config): void
    {
        // Check for required keys
        foreach (self::REQUIRED_CONFIG as $key) {
            if (!isset($config[$key])) {
                throw new ConfigurationException("Configuration key '$key' is required");
            }
        }

        // Check for invalid keys
        foreach ($config as $key => $value) {
            if (!in_array($key, self::ALLOWED_CONFIG)) {
                throw new ConfigurationException("Invalid configuration key: '$key'");
            }
        }

        // Validate specific options
        if (isset($config['verify_ssl']) && !is_bool($config['verify_ssl'])) {
            throw new ConfigurationException('verify_ssl must be boolean');
        }

        if (isset($config['timeout']) && (!is_int($config['timeout']) || $config['timeout'] < 1)) {
            throw new ConfigurationException('timeout must be a positive integer');
        }

        // Validate retry options
        if (isset($config['retry_enabled']) && !is_bool($config['retry_enabled'])) {
            throw new ConfigurationException('retry_enabled must be boolean');
        }

        if (isset($config['retry_max_attempts'])) {
            if (
                !is_int($config['retry_max_attempts'])
                || $config['retry_max_attempts'] < 1
                || $config['retry_max_attempts'] > 10
            ) {
                throw new ConfigurationException('retry_max_attempts must be integer between 1 and 10');
            }
        }

        if (
            isset($config['retry_initial_delay_ms'])
            && (!is_int($config['retry_initial_delay_ms']) || $config['retry_initial_delay_ms'] < 1)
        ) {
            throw new ConfigurationException('retry_initial_delay_ms must be a positive integer');
        }

        if (
            isset($config['retry_max_delay_ms'])
            && (!is_int($config['retry_max_delay_ms']) || $config['retry_max_delay_ms'] < 1)
        ) {
            throw new ConfigurationException('retry_max_delay_ms must be a positive integer');
        }

        if (isset($config['retry_multiplier'])) {
            if (!is_float($config['retry_multiplier']) && !is_int($config['retry_multiplier'])) {
                throw new ConfigurationException('retry_multiplier must be numeric');
            }
            if ($config['retry_multiplier'] < 1.0 || $config['retry_multiplier'] > 5.0) {
                throw new ConfigurationException('retry_multiplier must be between 1.0 and 5.0');
            }
        }

        if (isset($config['retry_jitter']) && !is_bool($config['retry_jitter'])) {
            throw new ConfigurationException('retry_jitter must be boolean');
        }
    }

    /**
     * Get all configuration values as array.
     *
     * @return array<string, mixed> Configuration values
     */
    public function toArray(): array
    {
        return $this->config;
    }

    /**
     * Get a specific configuration value.
     *
     * @param string $key Configuration key
     * @param mixed $default Default value if key is not set
     * @return mixed Configuration value or default
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Get the base URL without trailing slash.
     *
     * @return string Base URL
     */
    public function getBaseUrl(): string
    {
        return rtrim($this->get('url'), '/');
    }

    /**
     * Get transport-only HTTP headers (x-devapp, Accept).
     *
     * Context headers (Authorization, Accept-Language, x-sid, Client-Ip, User-Agent)
     * are handled via defaultOptions → merge → extractPerCallOptions in GuzzleHttpClient.
     *
     * @return array<string, string> Transport headers
     */
    public function getHeaders(): array
    {
        return array_filter([
            'Accept' => 'application/json',
            'x-devapp' => $this->get('key'),
        ]);
    }
}
