<?php

use App\Jobs\PruneRefreshTokensJob;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('auth:prune-refresh-tokens', function () {
    PruneRefreshTokensJob::dispatch();
    $this->info('Queued the refresh token cleanup');
})->purpose('Delete refresh tokens that expired a while ago');

Schedule::command('auth:prune-refresh-tokens')->daily();
