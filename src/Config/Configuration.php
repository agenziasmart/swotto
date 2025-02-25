<?php

declare(strict_types=1);

namespace Swotto\Config;

use Swotto\Exception\ConfigurationException;

/**
 * Configuration
 *
 * Manages and validates Swotto Client configuration
 */
class Configuration
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
    'headers'
  ];

  /**
   * @var array<string, mixed> Configuration values
   */
  private array $config;

  /**
   * Constructor
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
   * Validate configuration options
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
  }

  /**
   * Get all configuration values as array
   *
   * @return array<string, mixed> Configuration values
   */
  public function toArray(): array
  {
    return $this->config;
  }

  /**
   * Get a specific configuration value
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
   * Update configuration with new values
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
   * Get the base URL without trailing slash
   *
   * @return string Base URL
   */
  public function getBaseUrl(): string
  {
    return rtrim($this->get('url'), '/');
  }

  /**
   * Get HTTP headers from configuration
   *
   * @return array<string, string> Headers
   */
  public function getHeaders(): array
  {
    return array_filter([
      'Accept' => $this->get('accept', 'application/json'),
      'Accept-Language' => $this->get('language', 'en'),
      'Authorization' => $this->get('access_token') ? "Bearer {$this->get('access_token')}" : null,
      'x-devapp' => $this->get('key'),
      'x-sid' => $this->get('session_id'),
    ]);
  }
}
