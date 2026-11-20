<?php

namespace App\Http\Resources;

use App\Models\CreditTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/** @mixin CreditTransaction */
class CreditTransactionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CreditTransaction $transaction */
        $transaction = $this->resource;

        return [
            'id' => $transaction->id,
            'type' => $transaction->type->value,
            'label' => $transaction->type->label(),
            'amount_nanos' => (string) $transaction->amount_nanos,
            'balance_after_nanos' => (string) $transaction->balance_after_nanos,
            'generation_id' => $transaction->generation_id,
            'generation_prompt' => $transaction->generation === null ? null : Str::limit($transaction->generation->prompt, 60),
            'created_at' => $transaction->created_at->toIso8601String(),
        ];
    }
}
