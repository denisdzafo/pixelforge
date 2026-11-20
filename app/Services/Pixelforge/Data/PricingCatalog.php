<?php

declare(strict_types=1);

namespace App\Services\Pixelforge\Data;

use App\Support\Money;
use InvalidArgumentException;

final readonly class PricingCatalog
{
    /**
     * @param  array<string, array<string, int>>  $outputTokens
     * @param  array<string, array{min: int, max: int}>  $reasoningTokens
     */
    public function __construct(
        public Rates $rates,
        public int $charactersPerTextToken,
        public int $textTokenVarianceBps,
        public int $tokensPerReferenceImage,
        public array $outputTokens,
        public array $reasoningTokens,
    ) {}

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $tokens = is_array($data['tokens'] ?? null) ? $data['tokens'] : [];

        $output = [];
        foreach ((array) ($tokens['output'] ?? []) as $quality => $sizes) {
            foreach ((array) $sizes as $size => $count) {
                $output[(string) $quality][(string) $size] = (int) $count;
            }
        }

        $reasoning = [];
        foreach ((array) ($tokens['reasoning'] ?? []) as $quality => $range) {
            $range = (array) $range;
            $reasoning[(string) $quality] = ['min' => (int) ($range['min'] ?? 0), 'max' => (int) ($range['max'] ?? 0)];
        }

        $charsPerToken = (int) ($tokens['characters_per_text_token'] ?? 0);
        if ($charsPerToken < 1 || $output === [] || $reasoning === []) {
            throw new InvalidArgumentException('Incomplete Pixelforge pricing');
        }

        return new self(
            rates: Rates::fromArray(is_array($data['rates'] ?? null) ? $data['rates'] : []),
            charactersPerTextToken: $charsPerToken,
            textTokenVarianceBps: Money::parseDecimal(self::decimalString($tokens['text_token_variance'] ?? '0'), 4),
            tokensPerReferenceImage: (int) ($tokens['per_reference_image'] ?? 0),
            outputTokens: $output,
            reasoningTokens: $reasoning,
        );
    }

    public function outputTokens(string $quality, string $size): int
    {
        return $this->outputTokens[$quality][$size]
            ?? throw new InvalidArgumentException("No output tokens for [{$quality}, {$size}]");
    }

    /** @return array{min: int, max: int} */
    public function reasoningRange(string $quality): array
    {
        return $this->reasoningTokens[$quality]
            ?? throw new InvalidArgumentException("No reasoning range for [{$quality}]");
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'rates' => $this->rates->toArray(),
            'characters_per_text_token' => $this->charactersPerTextToken,
            'text_token_variance_bps' => $this->textTokenVarianceBps,
            'per_reference_image' => $this->tokensPerReferenceImage,
            'output' => $this->outputTokens,
            'reasoning' => $this->reasoningTokens,
        ];
    }

    private static function decimalString(mixed $value): string
    {
        if (is_string($value) || is_int($value)) {
            return (string) $value;
        }

        return is_float($value) ? (string) json_encode($value) : '0';
    }
}
