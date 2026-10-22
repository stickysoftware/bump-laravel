<?php

namespace StickySoftware\Bump\Exceptions;

use RuntimeException;

final class BumpException extends RuntimeException
{
    /**
     * @param  int|null  $status  the HTTP status, null when there was no answer
     * @param  string|null  $errorCode  the API's error code, like "resource_missing"
     * @param  int|null  $retryAfter  seconds from a 429's Retry-After
     */
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $errorCode = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }
}
