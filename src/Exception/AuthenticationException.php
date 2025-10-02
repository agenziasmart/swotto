<?php

declare(strict_types=1);

// src/Exception/AuthenticationException.php

namespace Swotto\Exception;

class AuthenticationException extends ApiException
{
    /**
     * Constructor.
     *
     * @param string $message Error message
     * @param array $errorData Error data from the API response
     * @param int $code HTTP status code
     */
    public function __construct(string $message = 'Unauthorized', array $errorData = [], int $code = 401)
    {
        parent::__construct($message, $errorData, $code);
    }
}
