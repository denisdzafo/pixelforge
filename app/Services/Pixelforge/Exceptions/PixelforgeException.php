<?php

declare(strict_types=1);

namespace App\Services\Pixelforge\Exceptions;

use RuntimeException;
use Throwable;

class PixelforgeException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $errorCode = null,
        public readonly ?int $httpStatus = null,
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus ?? 0, $previous);
    }

    public function isRetryable(): bool
    {
        return false;
    }
}
