<?php

declare(strict_types=1);

namespace App\Services\Generations;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Repositories\GenerationRepository;
use App\Services\Pixelforge\Exceptions\ConcurrencyLimitException;
use App\Services\Pixelforge\Exceptions\ContentPolicyViolationException;
use App\Services\Pixelforge\Exceptions\DailyLimitExceededException;
use App\Services\Pixelforge\Exceptions\IdempotencyKeyReusedException;
use App\Services\Pixelforge\Exceptions\InvalidApiKeyException;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use App\Services\Pixelforge\Exceptions\ValidationFailedException;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Support\Facades\Log;

class GenerationSubmissionService
{
    public function __construct(
        private readonly GenerationRepository $generations,
        private readonly PixelforgeClient $client,
        private readonly GenerationStateMachine $stateMachine,
        private readonly GenerationSlots $slots,
    ) {}

    public function submit(string $generationId): ?int
    {
        $generation = $this->generations->findById($generationId);
        if ($generation === null || $generation->status !== GenerationStatus::Pending) {
            return null;
        }

        if ($generation->created_at->lt(now()->subMinutes((int) config('pixelforge.submit.give_up_after_minutes')))) {
            $this->giveUp($generation);

            return null;
        }

        if ($generation->next_attempt_at?->isFuture()) {
            return max(1, (int) ceil(now()->diffInSeconds($generation->next_attempt_at)));
        }

        if (! $this->slots->tryClaim($generation)) {
            return (int) config('pixelforge.submit.slot_wait_seconds');
        }

        try {
            $remote = $this->client->createGeneration($this->payload($generation), $generation->idempotency_key);
        } catch (PixelforgeException $e) {
            $this->slots->releaseClaim($generation);

            return $this->handleError($generation, $e);
        }

        $this->stateMachine->apply($remote, 'create_response', $generation->id);

        return null;
    }

    public function abandon(string $generationId, ?string $reason): void
    {
        Log::error('Generation submission job failed', ['generation_id' => $generationId, 'error' => $reason]);

        $this->stateMachine->failBeforeAcceptance(
            $generationId,
            'submit_failed',
            'We could not send this image to the provider. Please try again.',
        );
    }

    /** @return array{prompt: string, size: string, quality: string, reference_images?: list<string>, metadata: array<string, string>} */
    public function payload(Generation $generation): array
    {
        $payload = [
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'metadata' => [
                'generation_id' => $generation->id,
                'user_id' => (string) $generation->user_id,
            ],
        ];

        if ($generation->reference_images !== []) {
            $payload['reference_images'] = $generation->reference_images;
        }

        return $payload;
    }

    private function handleError(Generation $generation, PixelforgeException $e): ?int
    {
        $context = ['generation_id' => $generation->id, 'code' => $e->errorCode, 'status' => $e->httpStatus];

        if ($e->isRetryable()) {
            $isWaiting = $e instanceof ConcurrencyLimitException || $e->errorCode === 'local_budget';
            $attempts = $generation->submit_attempts + ($isWaiting ? 0 : 1);

            if ($attempts >= (int) config('pixelforge.submit.max_attempts')) {
                Log::warning('Giving up on a generation after repeated provider errors', $context + ['attempts' => $attempts]);
                $this->giveUp($generation, 'provider_unavailable');

                return null;
            }

            $delay = $this->delay($attempts, $e->retryAfter, $isWaiting);
            $this->generations->scheduleRetry($generation->id, $attempts, now()->addSeconds($delay));
            Log::info('Generation submission will be retried', $context + ['attempts' => $attempts, 'delay' => $delay]);

            return $delay;
        }

        [$code, $message] = match (true) {
            $e instanceof ContentPolicyViolationException => [
                'content_policy_violation',
                'This prompt was rejected by the content policy: '.$e->getMessage(),
            ],
            $e instanceof ValidationFailedException => [
                'validation_failed',
                'The image provider did not accept this request: '.$e->getMessage(),
            ],
            $e instanceof DailyLimitExceededException => [
                'daily_limit_exceeded',
                "The image provider's daily limit has been reached. Please try again after 00:00 UTC.",
            ],
            default => [
                $e->errorCode ?? 'provider_error',
                'Image generation is temporarily unavailable. Please try again later.',
            ],
        };

        if ($e instanceof InvalidApiKeyException) {
            Log::critical('Pixelforge rejected our API key', $context);
        } elseif ($e instanceof IdempotencyKeyReusedException) {
            Log::error('Pixelforge says the idempotency key was reused with other parameters', $context);
        } else {
            Log::warning('Pixelforge rejected a generation', $context);
        }

        $this->stateMachine->failBeforeAcceptance($generation->id, $code, $message);

        return null;
    }

    private function giveUp(Generation $generation, string $code = 'submit_timeout'): void
    {
        $this->stateMachine->failBeforeAcceptance(
            $generation->id,
            $code,
            'The image provider is too busy right now. Please try again in a few minutes.',
        );
    }

    private function delay(int $attempts, ?int $retryAfter, bool $isWaiting): int
    {
        $base = (int) config('pixelforge.submit.backoff_base_seconds');
        $max = (int) config('pixelforge.submit.backoff_max_seconds');
        $backoff = $isWaiting ? 0 : $base * (2 ** max(0, $attempts - 1));
        $delay = min($max, max($retryAfter ?? 0, $backoff, 1));

        return $delay + random_int(0, max(1, intdiv($delay, 4)));
    }
}
