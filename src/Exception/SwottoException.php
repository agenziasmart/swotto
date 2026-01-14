<?php

declare(strict_types=1);

namespace Swotto\Exception;

/**
 * SwottoException.
 *
 * Base exception for all Swotto exceptions
 */
class SwottoException extends \Exception implements SwottoExceptionInterface
{
    /**
     * @var array Error data
     */
    protected array $errorData = [];

    /**
     * Constructor.
     *
     * @param string $message Error message
     * @param array $errorData Error data
     * @param int $code Error code
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(
        string $message = '',
        array $errorData = [],
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->errorData = $errorData;
    }

    /**
     * Get error data.
     *
     * @return array Error data
     */
    public function getErrorData(): array
    {
        return $this->errorData;
    }

    /**
     * Get HTTP status code.
     *
     * @return int HTTP status code
     */
    public function getStatusCode(): int
    {
        return $this->code;
    }
}
