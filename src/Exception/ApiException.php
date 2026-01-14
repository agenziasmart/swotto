<?php

declare(strict_types=1);

namespace Swotto\Exception;

/**
 * ApiException.
 *
 * Exception for API errors
 */
class ApiException extends SwottoException implements SwottoExceptionInterface
{
    /**
     * Constructor.
     *
     * @param string $message Error message
     * @param array $errorData Error data from the API response
     * @param int $code HTTP status code
     */
    public function __construct(string $message, array $errorData = [], int $code = 0)
    {
        parent::__construct($message, $errorData, $code);
    }
}
