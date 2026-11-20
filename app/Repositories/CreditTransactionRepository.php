<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CreditTransactionRepository
{
    public function findByIdempotencyKey(string $key): ?CreditTransaction
    {
        return CreditTransaction::query()->where('idempotency_key', $key)->first();
    }

    public function findForGeneration(string $generationId, CreditTransactionType $type): ?CreditTransaction
    {
        return CreditTransaction::query()
            ->where('generation_id', $generationId)
            ->where('type', $type)
            ->first();
    }

    /** @param array<string, mixed>|null $meta */
    public function create(
        int $userId,
        CreditTransactionType $type,
        int $amountNanos,
        int $balanceAfterNanos,
        string $idempotencyKey,
        ?string $generationId = null,
        ?array $meta = null,
    ): CreditTransaction {
        $transaction = new CreditTransaction;
        $transaction->user_id = $userId;
        $transaction->type = $type;
        $transaction->amount_nanos = $amountNanos;
        $transaction->balance_after_nanos = $balanceAfterNanos;
        $transaction->generation_id = $generationId;
        $transaction->idempotency_key = $idempotencyKey;
        $transaction->meta = $meta;
        $transaction->save();

        return $transaction;
    }

    /** @return LengthAwarePaginator<int, CreditTransaction> */
    public function paginateForUser(int $userId, int $perPage): LengthAwarePaginator
    {
        return CreditTransaction::query()
            ->where('user_id', $userId)
            ->with('generation:id,prompt')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }
}
