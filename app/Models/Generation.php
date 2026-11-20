<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use App\Services\Pixelforge\Data\Rates;
use App\Services\Pixelforge\Data\Usage;
use App\Support\Money;
use Carbon\CarbonInterface;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property int|null $user_id
 * @property string|null $pixelforge_id
 * @property string $idempotency_key
 * @property string|null $retry_of_id
 * @property GenerationStatus $status
 * @property string $prompt
 * @property string $size
 * @property string $quality
 * @property list<string> $reference_images
 * @property array<string, string>|null $rates
 * @property int $estimated_min_nanos
 * @property int $estimated_expected_nanos
 * @property int $estimated_max_nanos
 * @property int $hold_nanos
 * @property int|null $provider_cost_nanos
 * @property int|null $charged_nanos
 * @property int|null $input_text_tokens
 * @property int|null $input_image_tokens
 * @property int|null $output_tokens
 * @property int|null $reasoning_tokens
 * @property string|null $image_url
 * @property CarbonInterface|null $image_url_expires_at
 * @property string|null $error_code
 * @property string|null $error_message
 * @property int $submit_attempts
 * @property CarbonInterface|null $next_attempt_at
 * @property CarbonInterface|null $slot_claimed_at
 * @property CarbonInterface|null $last_polled_at
 * @property CarbonInterface|null $pixelforge_created_at
 * @property CarbonInterface|null $pixelforge_started_at
 * @property CarbonInterface|null $pixelforge_completed_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['*'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'reference_images' => '[]',
        'submit_attempts' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'reference_images' => 'array',
            'rates' => 'array',
            'estimated_min_nanos' => 'integer',
            'estimated_expected_nanos' => 'integer',
            'estimated_max_nanos' => 'integer',
            'hold_nanos' => 'integer',
            'provider_cost_nanos' => 'integer',
            'charged_nanos' => 'integer',
            'input_text_tokens' => 'integer',
            'input_image_tokens' => 'integer',
            'output_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'submit_attempts' => 'integer',
            'image_url_expires_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'slot_claimed_at' => 'datetime',
            'last_polled_at' => 'datetime',
            'pixelforge_created_at' => 'datetime',
            'pixelforge_started_at' => 'datetime',
            'pixelforge_completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<CreditTransaction, $this> */
    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    public function hold(): Money
    {
        return Money::nanos($this->hold_nanos);
    }

    public function ratesData(): ?Rates
    {
        return $this->rates === null ? null : Rates::fromArray($this->rates);
    }

    public function usage(): ?Usage
    {
        if ($this->output_tokens === null) {
            return null;
        }

        return new Usage(
            (int) $this->input_text_tokens,
            (int) $this->input_image_tokens,
            (int) $this->output_tokens,
            (int) $this->reasoning_tokens,
        );
    }

    public function imageExpired(): bool
    {
        return $this->image_url_expires_at !== null && $this->image_url_expires_at->isPast();
    }
}
