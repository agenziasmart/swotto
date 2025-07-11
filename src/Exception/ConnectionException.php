<?php

declare(strict_types=1);

namespace Swotto\Exception;

/**
 * ConnectionException.
 *
 * Exception for connection errors
 */
class ConnectionException extends NetworkException
{
    /**
     * @var string URL that failed to connect
     */
    private string $url;

    /**
     * @var array Trace details
     */
    private array $traceDetails;

    /**
     * Constructor.
     *
     * @param string $message Error message
     * @param string $url URL that failed to connect
     * @param array $traceDetails Trace details
     * @param int $code Error code
     */
    public function __construct(string $message, string $url, array $traceDetails = [], int $code = 0)
    {
        parent::__construct($message, [], $code);
        $this->url = $url;
        $this->traceDetails = $traceDetails;
    }

    /**
     * Get the URL that failed to connect.
     *
     * @return string URL
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * Get trace details.
     *
     * @return array Trace details
     */
    public function getTraceDetails(): array
    {
        return $this->traceDetails;
    }

    /**
     * {@inheritdoc}
     */
    public function getErrorData(): array
    {
        return [
          'message' => $this->getMessage(),
          'url' => $this->url,
          'trace' => $this->traceDetails,
          'code' => $this->getCode(),
        ];
    }
}
