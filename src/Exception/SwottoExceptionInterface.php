<?php

declare(strict_types=1);

namespace Swotto\Exception;

/**
 * SwottoExceptionInterface.
 *
 * Base interface for all Swotto exceptions
 */
interface SwottoExceptionInterface
{
    /**
     * Get error data.
     *
     * @return array Error data
     */
    public function getErrorData(): array;

    /**
     * Get HTTP status code.
     *
     * @return int HTTP status code
     */
    public function getStatusCode(): int;

    /**
     * Get error message.
     *
     * @return string Error message
     */
    public function getMessage(): string;
}
