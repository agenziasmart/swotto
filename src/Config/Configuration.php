<?php

declare(strict_types=1);

namespace Swotto\Config;

use Swotto\Exception\ConfigurationException;

/**
 * Configuration.
 *
 * Manages and validates Swotto Client configuration
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
      'url',
      'key',
      'access_token',
      'session_id',
      'language',
      'accept',
      'verify_ssl',
      'headers',
      'client_user_agent',
      'client_ip',
      'circuit_breaker_enabled',
      'circuit_breaker_failure_threshold',
      'circuit_breaker_recovery_timeout',
      // Retry configuration
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

        // Validate circuit breaker options
        if (isset($config['circuit_breaker_enabled']) && !is_bool($config['circuit_breaker_enabled'])) {
            throw new ConfigurationException('circuit_breaker_enabled must be boolean');
        }

        if (
            isset($config['circuit_breaker_failure_threshold'])
            && (!is_int($config['circuit_breaker_failure_threshold'])
                || $config['circuit_breaker_failure_threshold'] < 1)
        ) {
            throw new ConfigurationException('circuit_breaker_failure_threshold must be a positive integer');
        }

        if (
            isset($config['circuit_breaker_recovery_timeout'])
            && (!is_int($config['circuit_breaker_recovery_timeout'])
                || $config['circuit_breaker_recovery_timeout'] < 1)
        ) {
            throw new ConfigurationException('circuit_breaker_recovery_timeout must be a positive integer');
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
    public function get(string $key, $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Update configuration with new values.
     *
     * @param array<string, mixed> $newConfig New configuration options
     * @return self New configuration instance
     *
     * @throws ConfigurationException On invalid configuration
     */
    public function update(array $newConfig): self
    {
        $updatedConfig = array_merge($this->config, $newConfig);

        return new self($updatedConfig);
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
     * Check if an access token is configured.
     *
     * @return bool True if access token is set and not empty
     */
    public function hasAccessToken(): bool
    {
        $token = $this->get('access_token');

        return $token !== null && $token !== '';
    }

    /**
     * Sanitize a value to be used as HTTP header.
     *
     * Removes CRLF sequences and null bytes to prevent HTTP header injection attacks (CWE-113).
     *
     * @param string $value The value to sanitize
     * @return string Sanitized value safe for use in HTTP headers
     */
    private function sanitizeHeaderValue(string $value): string
    {
        // Remove carriage return, line feed, and null bytes
        return preg_replace('/[\r\n\0]/', '', $value) ?? '';
    }

    /**
     * Detect and return the client's User-Agent string.
     *
     * In web context, retrieves the User-Agent from the HTTP request headers.
     * In CLI context, returns the configured client_user_agent value.
     * The returned value is sanitized to prevent HTTP header injection attacks.
     *
     * @return string|null The sanitized User-Agent string, or null if not available
     */
    public function detectClientUserAgent(): ?string
    {
        if (PHP_SAPI !== 'cli' && isset($_SERVER['HTTP_USER_AGENT'])) {
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

            return $userAgent !== null ? $this->sanitizeHeaderValue($userAgent) : null;
        }

        $configValue = $this->get('client_user_agent', null);

        return $configValue !== null ? $this->sanitizeHeaderValue((string) $configValue) : null;
    }

    /**
     * Detect and return the client's IP address.
     *
     * In web context, checks multiple sources in order of reliability:
     * 1. HTTP_CLIENT_IP - Direct client IP (if set)
     * 2. HTTP_X_FORWARDED_FOR - First IP in proxy chain (if behind proxy)
     * 3. REMOTE_ADDR - Direct connection IP (may be proxy IP)
     *
     * In CLI context, returns the configured client_ip value.
     * The returned value is sanitized to prevent HTTP header injection attacks.
     *
     * @return string|null The sanitized client IP address, or null if not available
     */
    public function detectClientIp(): ?string
    {
        if (PHP_SAPI !== 'cli') {
            // Check standard headers that might contain the real IP
            if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
                return $this->sanitizeHeaderValue($_SERVER['HTTP_CLIENT_IP']);
            }

            // Check for proxy forwarded IP
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                // HTTP_X_FORWARDED_FOR may contain a list of IPs, we take the first one
                $ipList = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);

                return $this->sanitizeHeaderValue(trim($ipList[0]));
            }

            // REMOTE_ADDR is the most reliable but might be the proxy's IP
            $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;

            return $remoteAddr !== null ? $this->sanitizeHeaderValue($remoteAddr) : null;
        }

        $configValue = $this->get('client_ip', null);

        return $configValue !== null ? $this->sanitizeHeaderValue((string) $configValue) : null;
    }

    /**
     * Get HTTP headers from configuration.
     *
     * @return array<string, string> Headers
     */
    public function getHeaders(): array
    {
        $clientIp = $this->detectClientIp();
        $clientUa = $this->detectClientUserAgent();

        return array_filter([
          'Accept' => $this->get('accept', 'application/json'),
          'Accept-Language' => $this->get('language', 'en'),
          'Authorization' => $this->get('access_token') ? "Bearer {$this->get('access_token')}" : null,
          'x-devapp' => $this->get('key'),
          'x-sid' => $this->get('session_id'),
          'User-Agent' => $clientUa,
          'Client-Ip' => $clientIp,
        ]);
    }
}
