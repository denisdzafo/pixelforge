<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Generations\GenerationSubmissionService;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SubmitGenerationJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3600;

    public int $maxExceptions = 3;

    public function __construct(public string $generationId) {}

    public function uniqueId(): string
    {
        return $this->generationId;
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes((int) config('pixelforge.submit.give_up_after_minutes') + 5);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function handle(GenerationSubmissionService $submissions): void
    {
        $retryIn = $submissions->submit($this->generationId);

        if ($retryIn !== null) {
            $this->release($retryIn);
        }
    }

    public function failed(?Throwable $e): void
    {
        app(GenerationSubmissionService::class)->abandon($this->generationId, $e?->getMessage());
    }
}
