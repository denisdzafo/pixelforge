<?php

declare(strict_types=1);

namespace App\Services\Pixelforge\Exceptions;

class OverloadedException extends PixelforgeException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
