<?php

declare(strict_types=1);

namespace App\Services\Pixelforge\Data;

final readonly class Usage
{
    public function __construct(
        public int $inputTextTokens,
        public int $inputImageTokens,
        public int $outputTokens,
        public int $reasoningTokens,
    ) {}

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            self::int($data, 'input_text_tokens'),
            self::int($data, 'input_image_tokens'),
            self::int($data, 'output_tokens'),
            self::int($data, 'reasoning_tokens'),
        );
    }

    public function total(): int
    {
        return $this->inputTextTokens + $this->inputImageTokens + $this->outputTokens + $this->reasoningTokens;
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'input_text_tokens' => $this->inputTextTokens,
            'input_image_tokens' => $this->inputImageTokens,
            'output_tokens' => $this->outputTokens,
            'reasoning_tokens' => $this->reasoningTokens,
        ];
    }

    /** @param array<mixed> $data */
    private static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }
}
