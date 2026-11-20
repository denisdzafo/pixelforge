<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use RuntimeException;

class InvalidSignatureException extends RuntimeException {}
