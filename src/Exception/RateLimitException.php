<?php

declare(strict_types=1);

// src/Exception/RateLimitException.php

namespace Swotto\Exception;

class RateLimitException extends ApiException
{
    /**
     * @var int Seconds to wait before retrying
     */
    private int $retryAfter;

    /**
     * Constructor.
     *
     * @param string $message Error message
     * @param array $errorData Error data from the API response
     * @param int $retryAfter Seconds to wait before retrying
     */
    public function __construct(string $message = 'Too Many Requests', array $errorData = [], int $retryAfter = 0)
    {
        parent::__construct($message, $errorData, 429);
        $this->retryAfter = $retryAfter;
    }

    /**
     * Get seconds to wait before retrying.
     *
     * @return int Seconds to wait
     */
    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }

    /**
     * {@inheritdoc}
     */
    public function getErrorData(): array
    {
        return array_merge(
            parent::getErrorData(),
            ['retry_after' => $this->retryAfter]
        );
    }
}
