<?php

declare(strict_types=1);

namespace Swotto\Exception;

/**
 * NetworkException.
 *
 * Exception for network errors
 */
class NetworkException extends SwottoException implements SwottoExceptionInterface
{
    /**
     * {@inheritdoc}
     */
    public function getErrorData(): array
    {
        return [
          'message' => $this->getMessage(),
          'code' => $this->getCode(),
        ];
    }
}
