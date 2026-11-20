<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['pixelforge.base_url' => 'https://pf.test/v1', 'pixelforge.api_key' => 'sk_test']);
    Http::preventStrayRequests();
    $this->user = fundedUser('5.00');
    $this->generation = requestGeneration($this->user);
    $this->balanceAfterHold = $this->user->refresh()->balance_nanos;
});

function runSubmitJob(Generation $generation): SubmitGenerationJob
{
    $job = (new SubmitGenerationJob($generation->id))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    return $job;
}

function pfErrorResponse(string $code, int $status, array $headers = [], string $message = 'nope')
{
    return Http::response(['error' => ['type' => 't', 'code' => $code, 'message' => $message]], $status, $headers);
}

it('submits with our uuid as idempotency key and metadata, and stores the accepted generation', function () {
    Http::fake(['pf.test/v1/generations' => Http::response(pixelforgeGeneration([
        'id' => 'gen_ok', 'metadata' => ['generation_id' => $this->generation->id],
    ]), 202)]);

    runSubmitJob($this->generation)->assertNotReleased();

    $generation = $this->generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->pixelforge_id)->toBe('gen_ok')
        ->and($generation->rates)->toBe(pixelforgeGeneration()['rates']);

    Http::assertSent(fn (Request $r) => $r->hasHeader('Idempotency-Key', $this->generation->id)
        && $r['metadata'] === ['generation_id' => $this->generation->id, 'user_id' => (string) $this->user->id]
        && ! array_key_exists('reference_images', $r->data())
        && array_keys($r->data()) === ['prompt', 'size', 'quality', 'metadata']);
});

it('always sends the same idempotency key on retries', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['error' => ['code' => 'overloaded', 'message' => 'busy']], 503, ['Retry-After' => '2'])
        ->push(pixelforgeGeneration(['id' => 'gen_ok']), 202)]);

    runSubmitJob($this->generation)->assertReleased();
    $this->generation->refresh()->forceFill(['next_attempt_at' => null])->save();
    runSubmitJob($this->generation);

    $keys = collect(Http::recorded())->map(fn ($pair) => $pair[0]->header('Idempotency-Key')[0])->unique()->values()->all();
    expect($keys)->toBe([$this->generation->id])
        ->and($this->generation->refresh()->status)->toBe(GenerationStatus::Queued);
});

it('releases with Retry-After on a 429 rate limit', function () {
    Http::fake(['*' => pfErrorResponse('rate_limit_exceeded', 429, ['Retry-After' => '20'])]);

    $job = runSubmitJob($this->generation);

    $job->assertReleased();
    expect($job->job->releaseDelay)->toBeGreaterThanOrEqual(20)->toBeLessThanOrEqual(25)
        ->and($this->generation->refresh()->status)->toBe(GenerationStatus::Pending)
        ->and($this->generation->submit_attempts)->toBe(1);
});

it('retries a 503 overloaded and a 500', function (int $status, string $code) {
    Http::fake(['*' => pfErrorResponse($code, $status, ['Retry-After' => '2'])]);

    runSubmitJob($this->generation)->assertReleased();

    expect($this->generation->refresh()->status)->toBe(GenerationStatus::Pending)
        ->and($this->generation->next_attempt_at)->not->toBeNull();
})->with([[503, 'overloaded'], [500, 'internal']]);

it('retries a network timeout (unknown outcome) with the same key', function () {
    Http::fake(fn () => throw new ConnectionException('timeout'));

    runSubmitJob($this->generation)->assertReleased();
    expect($this->generation->refresh()->status)->toBe(GenerationStatus::Pending);
});

it('waits on the concurrency limit without using up attempts', function () {
    Http::fake(['*' => pfErrorResponse('concurrency_limit_exceeded', 429, ['Retry-After' => '5'])]);

    runSubmitJob($this->generation)->assertReleased();
    expect($this->generation->refresh()->submit_attempts)->toBe(0);
});

it('fails for free on a content policy violation, keeping the provider message', function () {
    Http::fake(['*' => pfErrorResponse('content_policy_violation', 400, message: 'The prompt violates our content policy.')]);

    runSubmitJob($this->generation)->assertNotReleased();

    $generation = $this->generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('content_policy_violation')
        ->and($generation->error_message)->toContain('The prompt violates our content policy.')
        ->and($generation->charged_nanos)->toBe(0)
        ->and($this->user->refresh()->balance_nanos)->toBe(5_000_000_000);
});

it('fails for free on the daily limit with a clear message', function () {
    Http::fake(['*' => pfErrorResponse('daily_limit_exceeded', 429)]);

    runSubmitJob($this->generation)->assertNotReleased();

    expect($this->generation->refresh()->status)->toBe(GenerationStatus::Failed)
        ->and($this->generation->error_message)->toContain('00:00 UTC')
        ->and($this->user->refresh()->balance_nanos)->toBe(5_000_000_000);
});

it('fails for free on 401, 409 and 422', function (string $code, int $status) {
    Http::fake(['*' => pfErrorResponse($code, $status)]);

    runSubmitJob($this->generation)->assertNotReleased();

    expect($this->generation->refresh()->status)->toBe(GenerationStatus::Failed)
        ->and($this->user->refresh()->balance_nanos)->toBe(5_000_000_000);
})->with([['invalid_api_key', 401], ['idempotency_key_reused', 409], ['validation_failed', 422]]);

it('gives up after the maximum attempts and releases the hold', function () {
    config(['pixelforge.submit.max_attempts' => 3]);
    Http::fake(['*' => pfErrorResponse('overloaded', 503, ['Retry-After' => '2'])]);

    foreach (range(1, 3) as $i) {
        $this->generation->refresh()->forceFill(['next_attempt_at' => null])->save();
        runSubmitJob($this->generation);
    }

    expect($this->generation->refresh()->status)->toBe(GenerationStatus::Failed)
        ->and($this->generation->error_code)->toBe('provider_unavailable')
        ->and($this->user->refresh()->balance_nanos)->toBe(5_000_000_000);
    Http::assertSentCount(3);
});

it('gives up on a generation pending for too long', function () {
    Http::fake();
    $this->generation->forceFill(['created_at' => now()->subHour()])->save();

    runSubmitJob($this->generation);

    expect($this->generation->refresh()->status)->toBe(GenerationStatus::Failed);
    Http::assertNothingSent();
});

it('waits for a free slot when 3 generations are in flight, without calling Pixelforge', function () {
    Http::fake();
    Generation::factory()->count(3)->create(['status' => GenerationStatus::Processing]);

    runSubmitJob($this->generation)->assertReleased();

    expect($this->generation->refresh()->status)->toBe(GenerationStatus::Pending);
    Http::assertNothingSent();
});

it('serves pending generations first come, first served', function () {
    Http::fake(['*' => Http::response(pixelforgeGeneration(), 202)]);
    Generation::factory()->count(2)->create(['status' => GenerationStatus::Processing]);
    Generation::factory()->create(['status' => GenerationStatus::Pending, 'created_at' => now()->subMinute()]);

    runSubmitJob($this->generation)->assertReleased();
    Http::assertNothingSent();
});

it('does nothing for a generation that is no longer pending', function () {
    Http::fake();
    $this->generation->forceFill(['status' => GenerationStatus::Queued])->save();

    runSubmitJob($this->generation)->assertNotReleased();
    Http::assertNothingSent();
});

it('marks the generation failed and free when the queue gives up on the job', function () {
    (new SubmitGenerationJob($this->generation->id))->failed(new RuntimeException('boom'));

    expect($this->generation->refresh()->status)->toBe(GenerationStatus::Failed)
        ->and($this->user->refresh()->balance_nanos)->toBe(5_000_000_000);
});

it('handles a create response that is already finished (idempotent replay)', function () {
    $base = pixelforgeGeneration(['id' => 'gen_r', 'metadata' => ['generation_id' => $this->generation->id]]);
    Http::fake(['*' => Http::response(pixelforgeSucceeded($base), 202, ['Idempotent-Replayed' => 'true'])]);

    runSubmitJob($this->generation);

    expect($this->generation->refresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($this->generation->charged_nanos)->toBeGreaterThan(0);
});
