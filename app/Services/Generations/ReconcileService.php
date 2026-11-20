<?php

declare(strict_types=1);

namespace App\Services\Generations;

use App\Jobs\SubmitGenerationJob;
use App\Repositories\GenerationRepository;
use App\Services\Pixelforge\Exceptions\NotFoundException;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Support\Facades\Log;

class ReconcileService
{
    private const int LOST_PENDING_BATCH = 50;

    public function __construct(
        private readonly GenerationRepository $generations,
        private readonly PixelforgeClient $client,
        private readonly GenerationStateMachine $stateMachine,
    ) {}

    /** @return array{polled: int, updated: int, redispatched: int} */
    public function reconcile(): array
    {
        $staleBefore = now()->subSeconds((int) config('pixelforge.reconcile.stale_after_seconds'));
        $stale = $this->generations->getStaleInFlight($staleBefore, (int) config('pixelforge.reconcile.batch_size'));

        $updated = 0;
        foreach ($stale as $generation) {
            $this->generations->markPolled($generation->id);

            try {
                $remote = $this->client->getGeneration((string) $generation->pixelforge_id);
            } catch (NotFoundException) {
                Log::error('Pixelforge does not know a generation we track', ['generation_id' => $generation->id]);

                continue;
            } catch (PixelforgeException $e) {
                Log::warning('Polling Pixelforge stopped early', ['error' => $e->getMessage()]);

                break;
            }

            $after = $this->stateMachine->apply($remote, 'poll');
            if ($after !== null && $after->status !== $generation->status) {
                $updated++;
            }
        }

        $lost = $this->generations->getOverduePendingIds(now()->subMinute(), now()->subMinute(), self::LOST_PENDING_BATCH);
        foreach ($lost as $id) {
            SubmitGenerationJob::dispatch($id);
        }

        return ['polled' => $stale->count(), 'updated' => $updated, 'redispatched' => count($lost)];
    }
}
