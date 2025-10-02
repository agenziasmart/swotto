<?php

declare(strict_types=1);

// src/Exception/ForbiddenException.php

namespace Swotto\Exception;

class ValidationException extends ApiException
{
    /**
     * Constructor.
     *
     * @param string $message Error message
     * @param array $errorData Error data from the API response
     * @param int $code HTTP status code
     */
    public function __construct(string $message = 'Invalid field', array $errorData = [], int $code = 400)
    {
        parent::__construct($message, $errorData, $code);
    }
}
