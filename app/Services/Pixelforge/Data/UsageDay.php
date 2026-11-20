<?php

declare(strict_types=1);

namespace App\Services\Pixelforge\Data;

use App\Support\Money;

final readonly class UsageDay
{
    public function __construct(
        public ?string $date,
        public int $generations,
        public Usage $usage,
        public Money $cost,
    ) {}

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            date: is_string($data['date'] ?? null) ? $data['date'] : null,
            generations: (int) ($data['generations'] ?? 0),
            usage: Usage::fromArray($data),
            cost: Money::fromUsd((string) ($data['cost_usd'] ?? '0')),
        );
    }
}
