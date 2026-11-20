<?php

use App\Services\Generations\ReconcileService;
use App\Services\Users\UserService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('app:make-admin {email}', function (UserService $users, string $email) {
    try {
        $user = $users->makeAdmin($email);
    } catch (ModelNotFoundException) {
        $this->error("No user with email {$email}");

        return 1;
    }

    $this->info("{$user->email} is now an admin");

    return 0;
})->purpose('Give a user access to the admin report');

Artisan::command('pixelforge:reconcile', function (ReconcileService $reconcile) {
    $result = $reconcile->reconcile();
    $this->info("Polled {$result['polled']}, updated {$result['updated']}, re-dispatched {$result['redispatched']}");
})->purpose('Poll Pixelforge for generations without recent webhooks, revive lost submissions');

Schedule::command('pixelforge:reconcile')->everyMinute()->withoutOverlapping(5);
