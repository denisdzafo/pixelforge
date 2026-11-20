<?php

declare(strict_types=1);

namespace App\Services\Generations;

use App\Models\Generation;
use App\Repositories\GenerationRepository;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

class GenerationSlots
{
    private const int CLAIM_TTL_SECONDS = 120;

    private const int IN_FLIGHT_TTL_MINUTES = 10;

    public function __construct(private readonly GenerationRepository $generations) {}

    public function tryClaim(Generation $generation): bool
    {
        try {
            return Cache::lock('pixelforge_generation_slots', 10)->block(5, fn () => $this->claim($generation));
        } catch (LockTimeoutException) {
            return false;
        }
    }

    public function releaseClaim(Generation $generation): void
    {
        $this->generations->setSlotClaimed($generation->id, null);
    }

    public function inFlight(?string $exceptId = null): int
    {
        return $this->generations->countInFlightSince(now()->subMinutes(self::IN_FLIGHT_TTL_MINUTES))
            + $this->generations->countClaimedSince(now()->subSeconds(self::CLAIM_TTL_SECONDS), $exceptId);
    }

    private function claim(Generation $generation): bool
    {
        $free = (int) config('pixelforge.limits.concurrent_generations') - $this->inFlight($generation->id);
        if ($free <= 0) {
            return false;
        }

        $next = $this->generations->nextPendingIds($free, now()->subSeconds(self::CLAIM_TTL_SECONDS), $generation->id);
        if (! in_array($generation->id, $next, true)) {
            return false;
        }

        $this->generations->setSlotClaimed($generation->id, now());

        return true;
    }
}
