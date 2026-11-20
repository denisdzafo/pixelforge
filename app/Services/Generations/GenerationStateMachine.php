<?php

declare(strict_types=1);

namespace App\Services\Generations;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Repositories\GenerationRepository;
use App\Services\Credits\CreditLedger;
use App\Services\Pixelforge\Data\GenerationData;
use App\Services\Pricing\PricingCalculator;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GenerationStateMachine
{
    public function __construct(
        private readonly GenerationRepository $generations,
        private readonly CreditLedger $ledger,
        private readonly PricingCalculator $calculator,
    ) {}

    public function apply(GenerationData $remote, string $source, ?string $localId = null): ?Generation
    {
        $localId ??= $this->findLocalId($remote);
        if ($localId === null) {
            Log::warning('Pixelforge update for an unknown generation', ['pixelforge_id' => $remote->id, 'source' => $source]);

            return null;
        }

        return DB::transaction(function () use ($localId, $remote, $source) {
            $generation = $this->generations->lockById($localId);
            if ($generation === null) {
                return null;
            }

            if ($generation->pixelforge_id !== null && $generation->pixelforge_id !== $remote->id) {
                Log::error('Pixelforge id does not match the generation', [
                    'generation_id' => $generation->id,
                    'pixelforge_id' => $generation->pixelforge_id,
                    'received_pixelforge_id' => $remote->id,
                ]);

                return $generation;
            }

            $generation->pixelforge_id ??= $remote->id;
            $generation->rates ??= $remote->rates->toArray();
            $generation->pixelforge_created_at ??= $remote->createdAt;

            $from = $generation->status;
            if ($remote->status->rank() <= $from->rank()) {
                if ($from->isTerminal() && $remote->status->isTerminal() && $remote->status !== $from) {
                    Log::warning('Pixelforge reports a different final status, keeping ours', [
                        'generation_id' => $generation->id,
                        'status' => $from->value,
                        'reported_status' => $remote->status->value,
                        'source' => $source,
                    ]);
                }
                $this->generations->save($generation);

                return $generation;
            }

            $generation->pixelforge_started_at ??= $remote->startedAt;
            $generation->slot_claimed_at = null;
            $generation->next_attempt_at = null;

            if ($remote->status === GenerationStatus::Succeeded && ! $this->succeed($generation, $remote)) {
                $this->generations->save($generation);

                return $generation;
            }

            $generation->status = $remote->status;
            if ($remote->status === GenerationStatus::Failed) {
                $this->fail($generation, $remote->errorCode ?? 'generation_failed', $this->failureMessage($remote->errorCode), $remote->completedAt);
            } else {
                $this->generations->save($generation);
            }

            Log::info('Generation status changed', [
                'generation_id' => $generation->id,
                'from' => $from->value,
                'to' => $generation->status->value,
                'source' => $source,
            ]);

            return $generation;
        });
    }

    public function failBeforeAcceptance(string $generationId, string $code, string $message): ?Generation
    {
        return DB::transaction(function () use ($generationId, $code, $message) {
            $generation = $this->generations->lockById($generationId);
            if ($generation === null || $generation->status !== GenerationStatus::Pending) {
                return $generation;
            }

            $generation->status = GenerationStatus::Failed;
            $generation->slot_claimed_at = null;
            $generation->next_attempt_at = null;
            $this->fail($generation, $code, $message, null);

            Log::info('Generation failed before Pixelforge accepted it', ['generation_id' => $generation->id, 'code' => $code]);

            return $generation;
        });
    }

    private function succeed(Generation $generation, GenerationData $remote): bool
    {
        if ($remote->usage === null) {
            Log::error('Succeeded generation without usage, not settled', ['generation_id' => $generation->id]);

            return false;
        }

        $providerCost = $this->calculator->providerCost($remote->usage, $remote->rates);
        $charge = $this->calculator->userCharge($providerCost);

        if ($charge->greaterThan($generation->hold())) {
            Log::critical('Actual charge exceeds the hold, capping it at the hold', [
                'generation_id' => $generation->id,
                'charge_nanos' => $charge->nanos,
                'hold_nanos' => $generation->hold_nanos,
                'usage' => $remote->usage->toArray(),
            ]);
            $charge = $generation->hold();
        }

        $generation->status = GenerationStatus::Succeeded;
        $generation->input_text_tokens = $remote->usage->inputTextTokens;
        $generation->input_image_tokens = $remote->usage->inputImageTokens;
        $generation->output_tokens = $remote->usage->outputTokens;
        $generation->reasoning_tokens = $remote->usage->reasoningTokens;
        $generation->provider_cost_nanos = $providerCost->nanos;
        $generation->charged_nanos = $charge->nanos;
        $generation->image_url = $remote->imageUrl;
        $generation->image_url_expires_at = $remote->imageUrlExpiresAt;
        $generation->pixelforge_completed_at = $remote->completedAt;
        $this->generations->save($generation);

        $this->ledger->settle($generation, $charge);

        return true;
    }

    private function fail(Generation $generation, string $code, string $message, ?CarbonInterface $completedAt): void
    {
        $generation->error_code = $code;
        $generation->error_message = $message;
        $generation->provider_cost_nanos = 0;
        $generation->charged_nanos = Money::zero()->nanos;
        $generation->pixelforge_completed_at = $completedAt;
        $this->generations->save($generation);

        $this->ledger->release($generation);
    }

    private function findLocalId(GenerationData $remote): ?string
    {
        $id = $this->generations->findIdByPixelforgeId($remote->id);
        if ($id !== null) {
            return $id;
        }

        $localId = $remote->localId();

        return $localId !== null && Str::isUuid($localId) ? $localId : null;
    }

    private function failureMessage(?string $code): string
    {
        return match ($code) {
            'internal_error' => 'Something went wrong at the image provider while generating. Retrying usually works.',
            default => 'The model could not produce an image for this prompt. Try rephrasing it.',
        };
    }
}
