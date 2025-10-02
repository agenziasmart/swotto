<?php

declare(strict_types=1);

// src/Exception/ForbiddenException.php

namespace Swotto\Exception;

class ForbiddenException extends ApiException
{
    /**
     * Constructor.
     *
     * @param string $message Error message
     * @param array $errorData Error data from the API response
     * @param int $code HTTP status code
     */
    public function __construct(string $message = 'Forbidden', array $errorData = [], int $code = 403)
    {
        parent::__construct($message, $errorData, $code);
    }
}
