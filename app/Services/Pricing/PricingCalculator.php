<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Services\Pixelforge\Data\PricingCatalog;
use App\Services\Pixelforge\Data\Rates;
use App\Services\Pixelforge\Data\Usage;
use App\Support\Money;
use Illuminate\Support\Facades\Log;

class PricingCalculator
{
    private const int TOKENS_PER_RATE_UNIT = 1_000_000;

    private const int BPS = 10_000;

    public function providerCost(Usage $usage, Rates $rates): Money
    {
        $total = 0;
        foreach ($usage->toArray() as $type => $tokens) {
            $total += $tokens * $rates->nanosPerMillion($type);
        }

        if ($total % self::TOKENS_PER_RATE_UNIT !== 0) {
            Log::warning('Pixelforge cost is not a whole number of nano-dollars, rounding down', [
                'usage' => $usage->toArray(),
                'rates' => $rates->toArray(),
            ]);
        }

        return Money::nanos(intdiv($total, self::TOKENS_PER_RATE_UNIT));
    }

    public function userCharge(Money $providerCost): Money
    {
        $scaled = $providerCost->nanos * (100 + $this->markupPercent());

        if ($scaled % 100 !== 0) {
            Log::info('User charge is not a whole number of nano-dollars, rounding down', [
                'provider_cost_nanos' => $providerCost->nanos,
            ]);
        }

        return Money::nanos(intdiv($scaled, 100));
    }

    /** @return list<array{type: string, tokens: int, rate_usd_per_million: string, cost_nanos: int}> */
    public function breakdown(Usage $usage, Rates $rates): array
    {
        $lines = [];
        foreach ($usage->toArray() as $type => $tokens) {
            $lines[] = [
                'type' => $type,
                'tokens' => $tokens,
                'rate_usd_per_million' => $rates->usdPerMillion[$type],
                'cost_nanos' => intdiv($tokens * $rates->nanosPerMillion($type), self::TOKENS_PER_RATE_UNIT),
            ];
        }

        return $lines;
    }

    public function markupPercent(): int
    {
        return (int) config('pixelforge.markup_percent');
    }

    public function estimate(string $prompt, string $size, string $quality, int $referenceImageCount, PricingCatalog $catalog): Estimate
    {
        $chars = mb_strlen($prompt, 'UTF-8');
        $text = intdiv($chars + $catalog->charactersPerTextToken - 1, $catalog->charactersPerTextToken);
        $textMin = intdiv($text * (self::BPS - $catalog->textTokenVarianceBps), self::BPS);
        $textMax = intdiv($text * (self::BPS + $catalog->textTokenVarianceBps) + self::BPS - 1, self::BPS);

        $image = $referenceImageCount * $catalog->tokensPerReferenceImage;
        $output = $catalog->outputTokens($quality, $size);
        $reasoning = $catalog->reasoningRange($quality);

        $min = new Usage($textMin, $image, $output, $reasoning['min']);
        $expected = new Usage($text, $image, $output, intdiv($reasoning['min'] + $reasoning['max'], 2));
        $max = new Usage($textMax, $image, $output, $reasoning['max']);

        return new Estimate(
            $min,
            $expected,
            $max,
            $this->userCharge($this->providerCost($min, $catalog->rates)),
            $this->userCharge($this->providerCost($expected, $catalog->rates)),
            $this->userCharge($this->providerCost($max, $catalog->rates)),
        );
    }
}
