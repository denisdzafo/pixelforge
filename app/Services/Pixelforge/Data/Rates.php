<?php

declare(strict_types=1);

namespace App\Services\Pixelforge\Data;

use App\Support\Money;
use InvalidArgumentException;

final readonly class Rates
{
    public const array TYPES = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    /** @param array<string, string> $usdPerMillion */
    private function __construct(public array $usdPerMillion) {}

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $rates = [];
        foreach (self::TYPES as $type) {
            $value = $data[$type] ?? null;
            if (! is_string($value) && ! is_int($value)) {
                throw new InvalidArgumentException("Missing rate [{$type}]");
            }
            Money::fromUsd((string) $value);
            $rates[$type] = (string) $value;
        }

        return new self($rates);
    }

    public function nanosPerMillion(string $type): int
    {
        return Money::fromUsd($this->usdPerMillion[$type])->nanos;
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return $this->usdPerMillion;
    }
}
