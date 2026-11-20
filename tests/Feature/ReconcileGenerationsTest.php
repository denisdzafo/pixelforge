<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['pixelforge.base_url' => 'https://pf.test/v1', 'pixelforge.api_key' => 'sk_test']);
    Http::preventStrayRequests();
    $this->user = fundedUser('5.00');
    $this->generation = requestGeneration($this->user);
    $this->generation->forceFill(['pixelforge_id' => 'gen_poll', 'status' => GenerationStatus::Processing, 'rates' => pixelforgeGeneration()['rates']])->save();
});

it('polls stale generations and settles them through the same state machine', function () {
    Generation::query()->whereKey($this->generation->id)->update(['updated_at' => now()->subMinutes(2)]);
    Http::fake(['pf.test/v1/generations/gen_poll' => Http::response(pixelforgeSucceeded(pixelforgeGeneration(['id' => 'gen_poll'])))]);

    $this->artisan('pixelforge:reconcile')->assertSuccessful();

    expect($this->generation->refresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($this->generation->charged_nanos)->toBe(70_700_000)
        ->and($this->user->refresh()->balance_nanos)->toBe(5_000_000_000 - 70_700_000);
});

it('leaves recently updated generations alone', function () {
    Http::fake();

    $this->artisan('pixelforge:reconcile')->assertSuccessful();

    Http::assertNothingSent();
});

it('does not double charge when polling and a webhook both deliver the outcome', function () {
    config(['pixelforge.webhook_secret' => 'whsec_test']);
    $succeeded = pixelforgeSucceeded(pixelforgeGeneration(['id' => 'gen_poll']));
    sendWebhook($this, webhookEvent('generation.succeeded', $succeeded))->assertOk();

    Generation::query()->whereKey($this->generation->id)->update(['status' => 'processing', 'updated_at' => now()->subMinutes(2)]);
    Http::fake(['*' => Http::response($succeeded)]);
    $this->artisan('pixelforge:reconcile')->assertSuccessful();

    expect($this->user->refresh()->balance_nanos)->toBe(5_000_000_000 - 70_700_000);
});

it('re-dispatches pending generations whose job got lost', function () {
    Queue::fake();
    $lost = requestGeneration($this->user);
    Generation::query()->whereKey($lost->id)->update(['created_at' => now()->subMinutes(5)]);

    $this->artisan('pixelforge:reconcile')->assertSuccessful();

    Queue::assertPushed(SubmitGenerationJob::class, fn ($job) => $job->generationId === $lost->id);
});

it('is scheduled every minute', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->command, 'pixelforge:reconcile'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('* * * * *');
});
