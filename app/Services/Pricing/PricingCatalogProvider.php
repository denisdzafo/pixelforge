<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Services\Pixelforge\Data\PricingCatalog;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PricingCatalogProvider
{
    public const string CACHE_KEY = 'pixelforge_pricing';

    public function __construct(private readonly PixelforgeClient $client) {}

    public function current(): PricingCatalog
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            return PricingCatalog::fromArray($cached);
        }

        try {
            $catalog = $this->client->getPricing();
        } catch (PixelforgeException $e) {
            Log::warning('Pixelforge pricing unavailable, using the documented fallback', ['error' => $e->getMessage()]);

            return $this->fallback();
        }

        Cache::put(self::CACHE_KEY, $this->toCacheable($catalog), (int) config('pixelforge.cache.pricing_seconds'));

        return $catalog;
    }

    public function fallback(): PricingCatalog
    {
        /** @var array<mixed> $pricing */
        $pricing = config('pixelforge.fallback_pricing');

        return PricingCatalog::fromArray($pricing);
    }

    /** @return array<string, mixed> */
    private function toCacheable(PricingCatalog $catalog): array
    {
        return [
            'rates' => $catalog->rates->toArray(),
            'tokens' => [
                'characters_per_text_token' => $catalog->charactersPerTextToken,
                'text_token_variance' => bcdiv((string) $catalog->textTokenVarianceBps, '10000', 4),
                'per_reference_image' => $catalog->tokensPerReferenceImage,
                'output' => $catalog->outputTokens,
                'reasoning' => $catalog->reasoningTokens,
            ],
        ];
    }
}
