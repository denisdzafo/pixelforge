<?php

declare(strict_types=1);

namespace App\Services\Credits;

use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Repositories\CreditTransactionRepository;
use App\Repositories\UserRepository;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;

class CreditLedger
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly CreditTransactionRepository $transactions,
    ) {}

    public function lockUser(int $userId): User
    {
        $this->assertInTransaction();

        return $this->users->lockById($userId);
    }

    public function grantSignupBonus(User $user): ?CreditTransaction
    {
        $bonus = Money::fromUsd((string) config('pixelforge.credits.signup_bonus_usd'));
        if ($bonus->nanos <= 0) {
            return null;
        }

        return DB::transaction(fn () => $this->recordOnce(
            $this->lockUser($user->id),
            CreditTransactionType::Bonus,
            $bonus,
            "bonus:{$user->id}",
        ));
    }

    public function purchase(User $user, Money $amount, string $clientKey): CreditTransaction
    {
        if ($amount->nanos <= 0) {
            throw new InvalidArgumentException('A purchase must be positive');
        }

        return DB::transaction(fn () => $this->recordOnce(
            $this->lockUser($user->id),
            CreditTransactionType::Purchase,
            $amount,
            "purchase:{$user->id}:{$clientKey}",
            meta: ['pack_usd' => $amount->toDecimal()],
        ));
    }

    public function hold(User $lockedUser, Generation $generation, Money $amount): CreditTransaction
    {
        if ($amount->greaterThan($lockedUser->balance())) {
            throw new LogicException('Hold exceeds the balance: the caller must check it under the lock');
        }

        return $this->recordOnce(
            $lockedUser,
            CreditTransactionType::Hold,
            $amount->negate(),
            "generation:{$generation->id}:hold",
            $generation,
        );
    }

    public function settle(Generation $generation, Money $charge): void
    {
        $user = $this->lockedOwner($generation);
        if ($user === null) {
            return;
        }

        $this->releaseHold($user, $generation);
        $this->recordOnce(
            $user,
            CreditTransactionType::Charge,
            $charge->negate(),
            "generation:{$generation->id}:charge",
            $generation,
            meta: ['provider_cost_nanos' => (string) $generation->provider_cost_nanos],
        );
    }

    public function release(Generation $generation): void
    {
        $user = $this->lockedOwner($generation);
        if ($user !== null) {
            $this->releaseHold($user, $generation);
        }
    }

    private function releaseHold(User $user, Generation $generation): void
    {
        $hold = $this->transactions->findForGeneration($generation->id, CreditTransactionType::Hold);

        if ($hold === null) {
            return;
        }

        $this->recordOnce(
            $user,
            CreditTransactionType::HoldRelease,
            $hold->amount()->negate(),
            "generation:{$generation->id}:hold_release",
            $generation,
        );
    }

    private function lockedOwner(Generation $generation): ?User
    {
        $this->assertInTransaction();

        if ($generation->user_id === null) {
            Log::warning('Generation without a user settled, ledger skipped', ['generation_id' => $generation->id]);

            return null;
        }

        return $this->lockUser($generation->user_id);
    }

    /** @param array<string, mixed>|null $meta */
    private function recordOnce(
        User $lockedUser,
        CreditTransactionType $type,
        Money $amount,
        string $idempotencyKey,
        ?Generation $generation = null,
        ?array $meta = null,
    ): CreditTransaction {
        $existing = $this->transactions->findByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            Log::info('Ledger entry already recorded, skipped', ['idempotency_key' => $idempotencyKey]);

            return $existing;
        }

        $this->users->updateBalance($lockedUser, $lockedUser->balance_nanos + $amount->nanos);

        return $this->transactions->create(
            $lockedUser->id,
            $type,
            $amount->nanos,
            $lockedUser->balance_nanos,
            $idempotencyKey,
            $generation?->id,
            $meta,
        );
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Ledger writes must run inside a database transaction');
        }
    }
}
