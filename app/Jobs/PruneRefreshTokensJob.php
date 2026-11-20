<?php

namespace App\Jobs;

use App\Repository\RefreshTokenRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Deletes refresh tokens that expired a while ago. Queued daily by auth:prune-refresh-tokens (routes/console.php).
 */
class PruneRefreshTokensJob implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public $backoff = [30, 60, 120];

    public function handle(RefreshTokenRepository $refreshTokenRepository): void
    {
        $deleted = $refreshTokenRepository->deleteExpiredBefore(now()->subDays(config('jwt.prune_after_days')));

        Log::info('Expired refresh tokens deleted', ['deleted' => $deleted]);
    }
}
