<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class GenerationRepository
{
    public function findById(string $id): ?Generation
    {
        return Generation::query()->find($id);
    }

    public function findForUser(string $id, int $userId): ?Generation
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        return Generation::query()->whereKey($id)->where('user_id', $userId)->first();
    }

    public function findIdByPixelforgeId(string $pixelforgeId): ?string
    {
        $id = Generation::query()->where('pixelforge_id', $pixelforgeId)->value('id');

        return is_string($id) ? $id : null;
    }

    public function lockById(string $id): ?Generation
    {
        return Generation::query()->whereKey($id)->lockForUpdate()->first();
    }

    /** @param list<string> $referenceImages */
    public function createPending(
        int $userId,
        string $prompt,
        string $size,
        string $quality,
        array $referenceImages,
        int $estimatedMinNanos,
        int $estimatedExpectedNanos,
        int $estimatedMaxNanos,
        ?string $retryOfId = null,
    ): Generation {
        $id = (string) Str::uuid7();

        $generation = new Generation;
        $generation->id = $id;
        $generation->user_id = $userId;
        $generation->idempotency_key = $id;
        $generation->retry_of_id = $retryOfId;
        $generation->status = GenerationStatus::Pending;
        $generation->prompt = $prompt;
        $generation->size = $size;
        $generation->quality = $quality;
        $generation->reference_images = $referenceImages;
        $generation->estimated_min_nanos = $estimatedMinNanos;
        $generation->estimated_expected_nanos = $estimatedExpectedNanos;
        $generation->estimated_max_nanos = $estimatedMaxNanos;
        $generation->hold_nanos = $estimatedMaxNanos;
        $generation->save();

        return $generation;
    }

    public function save(Generation $generation): void
    {
        $generation->save();
    }

    /** @return LengthAwarePaginator<int, Generation> */
    public function paginateForUser(int $userId, int $perPage): LengthAwarePaginator
    {
        return Generation::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function sumHoldsInProgressForUser(int $userId): int
    {
        return (int) Generation::query()
            ->where('user_id', $userId)
            ->whereNotIn('status', [GenerationStatus::Succeeded, GenerationStatus::Failed])
            ->sum('hold_nanos');
    }

    public function countInFlightSince(CarbonInterface $since): int
    {
        return Generation::query()
            ->whereIn('status', GenerationStatus::inFlight())
            ->where('updated_at', '>', $since)
            ->count();
    }

    public function countClaimedSince(CarbonInterface $since, ?string $exceptId = null): int
    {
        return Generation::query()
            ->where('status', GenerationStatus::Pending)
            ->where('slot_claimed_at', '>', $since)
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->count();
    }

    /** @return list<string> */
    public function nextPendingIds(int $limit, CarbonInterface $claimExpiredBefore, string $includeId): array
    {
        /** @var list<string> $ids */
        $ids = Generation::query()
            ->where('status', GenerationStatus::Pending)
            ->where(fn ($q) => $q->whereNull('slot_claimed_at')
                ->orWhere('slot_claimed_at', '<=', $claimExpiredBefore)
                ->orWhere('id', $includeId))
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->all();

        return $ids;
    }

    public function setSlotClaimed(string $id, ?CarbonInterface $at): void
    {
        Generation::query()->whereKey($id)->update(['slot_claimed_at' => $at]);
    }

    public function scheduleRetry(string $id, int $attempts, CarbonInterface $nextAttemptAt): void
    {
        Generation::query()->whereKey($id)->update([
            'submit_attempts' => $attempts,
            'next_attempt_at' => $nextAttemptAt,
        ]);
    }

    /** @return Collection<int, Generation> */
    public function getStaleInFlight(CarbonInterface $staleBefore, int $limit): Collection
    {
        return Generation::query()
            ->whereIn('status', GenerationStatus::inFlight())
            ->whereNotNull('pixelforge_id')
            ->where('updated_at', '<', $staleBefore)
            ->where(fn ($q) => $q->whereNull('last_polled_at')->orWhere('last_polled_at', '<', $staleBefore))
            ->orderBy('last_polled_at')
            ->orderBy('created_at')
            ->limit($limit)
            ->get();
    }

    public function markPolled(string $id): void
    {
        Generation::query()->whereKey($id)->update(['last_polled_at' => now()]);
    }

    /** @return list<string> */
    public function getOverduePendingIds(CarbonInterface $createdBefore, CarbonInterface $dueBefore, int $limit): array
    {
        /** @var list<string> $ids */
        $ids = Generation::query()
            ->where('status', GenerationStatus::Pending)
            ->where('created_at', '<', $createdBefore)
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<', $dueBefore))
            ->limit($limit)
            ->pluck('id')
            ->all();

        return $ids;
    }

    /** @return Collection<int, Generation> */
    public function getSucceededCompletedBetween(CarbonInterface $start, CarbonInterface $end): Collection
    {
        return Generation::query()
            ->where('status', GenerationStatus::Succeeded)
            ->whereBetween('pixelforge_completed_at', [$start, $end])
            ->get([
                'user_id',
                'charged_nanos',
                'provider_cost_nanos',
                'input_text_tokens',
                'input_image_tokens',
                'output_tokens',
                'reasoning_tokens',
                'pixelforge_completed_at',
            ]);
    }
}
