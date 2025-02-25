<?php

declare(strict_types=1);

namespace Swotto\Exception;

/**
 * SwottoException
 *
 * Base exception for all Swotto exceptions
 */
class SwottoException extends \Exception
{
  /**
   * @var array Error data
   */
  protected array $errorData = [];

  /**
   * Get error data
   *
   * @return array Error data
   */
  public function getErrorData(): array
  {
    return $this->errorData;
  }

  /**
   * Get HTTP status code
   *
   * @return int HTTP status code
   */
  public function getStatusCode(): int
  {
    return $this->code;
  }
}
