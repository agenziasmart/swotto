<?php

declare(strict_types=1);

// src/Exception/ForbiddenException.php

namespace Swotto\Exception;

class NotFoundException extends ApiException
{
    /**
     * Constructor.
     *
     * @param string $message Error message
     * @param array $errorData Error data from the API response
     * @param int $code HTTP status code
     */
    public function __construct(string $message = 'Not found', array $errorData = [], int $code = 404)
    {
        parent::__construct($message, $errorData, $code);
    }
}
