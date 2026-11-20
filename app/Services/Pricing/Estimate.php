<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Services\Pixelforge\Data\Usage;
use App\Support\Money;

final readonly class Estimate
{
    public function __construct(
        public Usage $minUsage,
        public Usage $expectedUsage,
        public Usage $maxUsage,
        public Money $min,
        public Money $expected,
        public Money $max,
    ) {}
}
