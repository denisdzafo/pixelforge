<?php

namespace App\Http\Resources;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Pricing\PricingCalculator;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Generation */
class GenerationResource extends JsonResource
{
    private const array TYPE_LABELS = [
        'input_text_tokens' => 'Prompt text',
        'input_image_tokens' => 'Reference images',
        'output_tokens' => 'Image output',
        'reasoning_tokens' => 'Reasoning',
    ];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Generation $generation */
        $generation = $this->resource;
        $expired = $generation->imageExpired();

        return [
            'id' => $generation->id,
            'status' => $generation->status->value,
            'is_terminal' => $generation->status->isTerminal(),
            'waiting_reason' => $this->waitingReason($generation),
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'reference_images' => $generation->reference_images,
            'estimate' => [
                'min_nanos' => (string) $generation->estimated_min_nanos,
                'expected_nanos' => (string) $generation->estimated_expected_nanos,
                'max_nanos' => (string) $generation->estimated_max_nanos,
            ],
            'hold_nanos' => (string) $generation->hold_nanos,
            'charged_nanos' => $generation->charged_nanos === null ? null : (string) $generation->charged_nanos,
            'breakdown' => $this->breakdown($generation),
            'image_url' => $expired ? null : $generation->image_url,
            'image_expired' => $expired && $generation->image_url !== null,
            'image_url_expires_at' => $generation->image_url_expires_at?->toIso8601String(),
            'error_code' => $generation->error_code,
            'error_message' => $generation->error_message,
            'created_at' => $generation->created_at->toIso8601String(),
            'completed_at' => $generation->pixelforge_completed_at?->toIso8601String(),
        ];
    }

    private function waitingReason(Generation $generation): ?string
    {
        if ($generation->status !== GenerationStatus::Pending) {
            return null;
        }

        return $generation->submit_attempts > 0
            ? 'The image provider is busy, retrying automatically'
            : 'Waiting for a free generation slot';
    }

    /** @return array<string, mixed>|null */
    private function breakdown(Generation $generation): ?array
    {
        $usage = $generation->usage();
        $rates = $generation->ratesData();
        if ($generation->status !== GenerationStatus::Succeeded || $usage === null || $rates === null) {
            return null;
        }

        $calculator = app(PricingCalculator::class);
        $lines = array_map(fn (array $line) => [
            'type' => $line['type'],
            'label' => self::TYPE_LABELS[$line['type']] ?? $line['type'],
            'tokens' => $line['tokens'],
            'rate_usd_per_million' => $line['rate_usd_per_million'],
            'cost_nanos' => (string) $line['cost_nanos'],
        ], $calculator->breakdown($usage, $rates));

        $provider = Money::nanos((int) $generation->provider_cost_nanos);
        $charged = Money::nanos((int) $generation->charged_nanos);

        return [
            'lines' => $lines,
            'provider_cost_nanos' => (string) $provider->nanos,
            'markup_percent' => $calculator->markupPercent(),
            'markup_nanos' => (string) $charged->minus($provider)->nanos,
            'charged_nanos' => (string) $charged->nanos,
        ];
    }
}
