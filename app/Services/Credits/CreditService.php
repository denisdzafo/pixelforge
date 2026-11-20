<?php

declare(strict_types=1);

namespace App\Services\Credits;

use App\Models\CreditTransaction;
use App\Models\User;
use App\Repositories\CreditTransactionRepository;
use App\Repositories\GenerationRepository;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

class CreditService
{
    private const int ACTIVITY_PER_PAGE = 20;

    public function __construct(
        private readonly CreditLedger $ledger,
        private readonly CreditTransactionRepository $transactions,
        private readonly GenerationRepository $generations,
    ) {}

    /** @return array{packs: list<array{usd: string, nanos: string}>, onHoldNanos: string, transactions: LengthAwarePaginator<int, CreditTransaction>} */
    public function pageData(User $user): array
    {
        return [
            'packs' => array_map(fn (string $usd) => [
                'usd' => $usd,
                'nanos' => (string) Money::fromUsd($usd)->nanos,
            ], $this->packs()),
            'onHoldNanos' => (string) $this->generations->sumHoldsInProgressForUser($user->id),
            'transactions' => $this->transactions->paginateForUser($user->id, self::ACTIVITY_PER_PAGE),
        ];
    }

    public function purchase(User $user, string $packUsd, string $idempotencyKey): Money
    {
        $amount = Money::fromUsd($packUsd);
        $this->ledger->purchase($user, $amount, $idempotencyKey);

        Log::info('Credits purchased', ['user_id' => $user->id, 'amount_nanos' => $amount->nanos]);

        return $amount;
    }

    /** @return list<string> */
    public function packs(): array
    {
        /** @var list<string> $packs */
        $packs = array_values((array) config('pixelforge.credits.packs'));

        return $packs;
    }
}
