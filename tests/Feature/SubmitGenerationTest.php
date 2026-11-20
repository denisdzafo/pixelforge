<?php

use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationJob;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Services\Pricing\PricingCatalogProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::put(PricingCatalogProvider::CACHE_KEY, pixelforgePricing(), 3600);
    Queue::fake([SubmitGenerationJob::class]);
});

it('creates a pending generation, holds the maximum and queues the submission without calling Pixelforge', function () {
    $user = fundedUser('1.00');

    $this->actingAs($user)->post(route('generations.store'), [
        'prompt' => str_repeat('a', 400),
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png', ''],
    ])->assertRedirect(route('generations.index'));

    $generation = Generation::query()->sole();
    expect($generation->status)->toBe(GenerationStatus::Pending)
        ->and($generation->idempotency_key)->toBe($generation->id)
        ->and($generation->reference_images)->toBe(['https://example.com/a.png', 'https://example.com/b.png'])
        ->and($generation->estimated_max_nanos)->toBe(467_343_750)
        ->and($generation->hold_nanos)->toBe(467_343_750)
        ->and($user->refresh()->balance_nanos)->toBe(1_000_000_000 - 467_343_750);

    Queue::assertPushed(SubmitGenerationJob::class, fn ($job) => $job->generationId === $generation->id);
    Http::assertNothingSent();
});

it('blocks a generation the balance cannot cover, with a clear message', function () {
    $user = fundedUser('0.10');

    $this->actingAs($user)->post(route('generations.store'), [
        'prompt' => 'x', 'size' => '1536x1024', 'quality' => 'high',
    ])->assertSessionHasErrors(['balance' => 'This image can cost up to $0.4475125 and your balance is $0.10. Buy credits to continue.']);

    expect(Generation::query()->count())->toBe(0)
        ->and($user->refresh()->balance_nanos)->toBe(100_000_000);
    Queue::assertNothingPushed();
});

it('validates the input like Pixelforge does', function (array $input, string $error) {
    $this->actingAs(fundedUser())
        ->post(route('generations.store'), array_replace(['prompt' => 'ok', 'size' => '1024x1024', 'quality' => 'low'], $input))
        ->assertSessionHasErrors($error);
})->with([
    'missing prompt' => [['prompt' => ''], 'prompt'],
    'long prompt' => [['prompt' => str_repeat('é', 4001)], 'prompt'],
    'bad size' => [['size' => '512x512'], 'size'],
    'bad quality' => [['quality' => 'ultra'], 'quality'],
    'five references' => [['reference_images' => array_fill(0, 5, 'https://e.com/a.png')], 'reference_images'],
    'ftp reference' => [['reference_images' => ['ftp://e.com/a.png']], 'reference_images.0'],
]);

it('accepts a 4000-character unicode prompt', function () {
    $this->actingAs(fundedUser())
        ->post(route('generations.store'), ['prompt' => str_repeat('é', 4000), 'size' => '1024x1024', 'quality' => 'low'])
        ->assertSessionHasNoErrors();
});

it('cannot overspend with concurrent submissions: the second sees the first hold', function () {
    $user = fundedUser('0.50');

    $first = requestGeneration($user, ['size' => '1536x1024', 'quality' => 'high', 'prompt' => 'x']);
    expect(fn () => requestGeneration($user, ['size' => '1536x1024', 'quality' => 'high', 'prompt' => 'x']))
        ->toThrow(ValidationException::class);

    expect(Generation::query()->count())->toBe(1)
        ->and($user->refresh()->balance_nanos)->toBe(500_000_000 - $first->hold_nanos)
        ->and($user->balance_nanos)->toBeGreaterThanOrEqual(0);
});

it('retries a failed generation as a new generation with the same settings', function () {
    $user = fundedUser();
    $failed = Generation::factory()->for($user)->create(['status' => GenerationStatus::Failed, 'prompt' => 'again', 'quality' => 'low']);

    $this->actingAs($user)->post(route('generations.retry', $failed))->assertRedirect(route('generations.index'));

    $retry = Generation::query()->where('retry_of_id', $failed->id)->sole();
    expect($retry->prompt)->toBe('again')
        ->and($retry->quality)->toBe('low')
        ->and($retry->idempotency_key)->not->toBe($failed->idempotency_key)
        ->and(CreditTransaction::query()->where('generation_id', $retry->id)->where('type', CreditTransactionType::Hold)->exists())->toBeTrue();
});

it('only retries failed generations', function () {
    $user = fundedUser();
    $succeeded = Generation::factory()->for($user)->create(['status' => GenerationStatus::Succeeded]);

    $this->actingAs($user)->post(route('generations.retry', $succeeded))->assertForbidden();
});
