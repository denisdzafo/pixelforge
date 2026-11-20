<?php

declare(strict_types=1);

namespace App\Services\Generations;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Models\User;
use App\Repositories\GenerationRepository;
use App\Services\Credits\CreditLedger;
use App\Services\Pricing\PricingCalculator;
use App\Services\Pricing\PricingCatalogProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class GenerationService
{
    public const int MAX_PROMPT_LENGTH = 4000;

    public const int MAX_REFERENCE_IMAGES = 4;

    private const int HISTORY_PER_PAGE = 12;

    public function __construct(
        private readonly GenerationRepository $generations,
        private readonly CreditLedger $ledger,
        private readonly PricingCalculator $calculator,
        private readonly PricingCatalogProvider $pricing,
    ) {}

    /** @return array<string, mixed> */
    public function formData(User $user, ?string $fromId): array
    {
        $from = $fromId === null ? null : $this->generations->findForUser($fromId, $user->id);

        return [
            'pricing' => $this->pricing->current()->toArray(),
            'markupPercent' => $this->calculator->markupPercent(),
            'sizes' => ImageSize::values(),
            'qualities' => ImageQuality::values(),
            'maxPromptLength' => self::MAX_PROMPT_LENGTH,
            'maxReferenceImages' => self::MAX_REFERENCE_IMAGES,
            'prefill' => $from === null ? null : [
                'prompt' => $from->prompt,
                'size' => $from->size,
                'quality' => $from->quality,
                'reference_images' => $from->reference_images,
            ],
        ];
    }

    /** @param array{prompt: string, size: string, quality: string, reference_images?: list<string>|null} $input */
    public function submit(User $user, array $input, ?Generation $retryOf = null): Generation
    {
        $references = array_values(array_filter($input['reference_images'] ?? [], fn ($url) => $url !== ''));
        $estimate = $this->calculator->estimate(
            $input['prompt'],
            $input['size'],
            $input['quality'],
            count($references),
            $this->pricing->current(),
        );

        $generation = DB::transaction(function () use ($user, $input, $references, $estimate, $retryOf) {
            $locked = $this->ledger->lockUser($user->id);

            if ($locked->balance()->lessThan($estimate->max)) {
                throw ValidationException::withMessages([
                    'balance' => sprintf(
                        'This image can cost up to %s and your balance is %s. Buy credits to continue.',
                        $estimate->max->format(),
                        $locked->balance()->format(),
                    ),
                ]);
            }

            $generation = $this->generations->createPending(
                $locked->id,
                $input['prompt'],
                $input['size'],
                $input['quality'],
                $references,
                $estimate->min->nanos,
                $estimate->expected->nanos,
                $estimate->max->nanos,
                $retryOf?->id,
            );

            $this->ledger->hold($locked, $generation, $estimate->max);

            return $generation;
        });

        SubmitGenerationJob::dispatch($generation->id);

        Log::info('Generation requested', [
            'generation_id' => $generation->id,
            'user_id' => $user->id,
            'hold_nanos' => $generation->hold_nanos,
        ]);

        return $generation;
    }

    public function retry(User $user, string $generationId): Generation
    {
        $failed = $this->generations->findForUser($generationId, $user->id)
            ?? throw (new ModelNotFoundException)->setModel(Generation::class, [$generationId]);

        if ($failed->status !== GenerationStatus::Failed) {
            throw new AuthorizationException('Only failed generations can be retried.');
        }

        return $this->submit($user, [
            'prompt' => $failed->prompt,
            'size' => $failed->size,
            'quality' => $failed->quality,
            'reference_images' => $failed->reference_images,
        ], $failed);
    }

    /** @return LengthAwarePaginator<int, Generation> */
    public function history(User $user): LengthAwarePaginator
    {
        return $this->generations->paginateForUser($user->id, self::HISTORY_PER_PAGE);
    }
}
