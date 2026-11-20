<?php

namespace App\Models;

use App\Enums\CreditTransactionType;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $user_id
 * @property CreditTransactionType $type
 * @property int $amount_nanos
 * @property int $balance_after_nanos
 * @property string|null $generation_id
 * @property string $idempotency_key
 * @property array<string, mixed>|null $meta
 * @property CarbonInterface $created_at
 */
class CreditTransaction extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => CreditTransactionType::class,
            'amount_nanos' => 'integer',
            'balance_after_nanos' => 'integer',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Generation, $this> */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }

    public function amount(): Money
    {
        return Money::nanos($this->amount_nanos);
    }
}
