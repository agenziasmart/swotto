<?php
// src/Exception/ConfigurationException.php
namespace Swotto\Exception;

class ConfigurationException extends SwottoException
{
  /**
   * {@inheritdoc}
   */
  public function getErrorData(): array
  {
    return [
      'message' => $this->getMessage(),
      'code' => $this->getCode()
    ];
  }
}
