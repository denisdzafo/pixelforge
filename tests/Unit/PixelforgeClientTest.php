<?php

use App\Services\Pixelforge\Exceptions\ConcurrencyLimitException;
use App\Services\Pixelforge\Exceptions\ConnectionFailedException;
use App\Services\Pixelforge\Exceptions\ContentPolicyViolationException;
use App\Services\Pixelforge\Exceptions\DailyLimitExceededException;
use App\Services\Pixelforge\Exceptions\IdempotencyKeyReusedException;
use App\Services\Pixelforge\Exceptions\InvalidApiKeyException;
use App\Services\Pixelforge\Exceptions\OverloadedException;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use App\Services\Pixelforge\Exceptions\RateLimitedException;
use App\Services\Pixelforge\Exceptions\ServerErrorException;
use App\Services\Pixelforge\Exceptions\ValidationFailedException;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pricing\PricingCatalogProvider;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['pixelforge.base_url' => 'https://pf.test/v1', 'pixelforge.api_key' => 'sk_test']);
    Http::preventStrayRequests();
});

function pfError(string $code, int $status, array $headers = []): PromiseInterface
{
    return Http::response(['error' => ['type' => 'x', 'code' => $code, 'message' => "msg {$code}"]], $status, $headers);
}

it('creates a generation with the idempotency key and bearer token', function () {
    Http::fake(['pf.test/v1/generations' => Http::response(pixelforgeGeneration(['id' => 'gen_1']), 202)]);

    $generation = app(PixelforgeClient::class)->createGeneration(
        ['prompt' => 'hi', 'size' => '1024x1024', 'quality' => 'low', 'metadata' => ['generation_id' => 'abc']],
        'abc',
    );

    expect($generation->id)->toBe('gen_1')->and($generation->status->value)->toBe('queued');
    Http::assertSent(fn (Request $r) => $r->hasHeader('Idempotency-Key', 'abc')
        && $r->hasHeader('Authorization', 'Bearer sk_test')
        && $r->method() === 'POST'
        && $r->data() === ['prompt' => 'hi', 'size' => '1024x1024', 'quality' => 'low', 'metadata' => ['generation_id' => 'abc']]);
});

it('maps every documented error to a typed exception', function (mixed $response, string $class, ?int $retryAfter, bool $retryable) {
    Http::fake(['*' => $response]);

    try {
        app(PixelforgeClient::class)->createGeneration(['prompt' => 'x', 'size' => '1024x1024', 'quality' => 'low'], 'k');
        $this->fail('No exception');
    } catch (PixelforgeException $e) {
        expect($e)->toBeInstanceOf($class)
            ->and($e->retryAfter)->toBe($retryAfter)
            ->and($e->isRetryable())->toBe($retryable);
    }
})->with([
    'rate limit' => [fn () => pfError('rate_limit_exceeded', 429, ['Retry-After' => '17']), RateLimitedException::class, 17, true],
    'concurrency' => [fn () => pfError('concurrency_limit_exceeded', 429, ['Retry-After' => '5']), ConcurrencyLimitException::class, 5, true],
    'daily' => [fn () => pfError('daily_limit_exceeded', 429), DailyLimitExceededException::class, null, false],
    'overloaded' => [fn () => pfError('overloaded', 503, ['Retry-After' => '2']), OverloadedException::class, 2, true],
    'content policy' => [fn () => pfError('content_policy_violation', 400), ContentPolicyViolationException::class, null, false],
    'validation' => [fn () => pfError('validation_failed', 422), ValidationFailedException::class, null, false],
    'idempotency' => [fn () => pfError('idempotency_key_reused', 409), IdempotencyKeyReusedException::class, null, false],
    'auth' => [fn () => pfError('invalid_api_key', 401), InvalidApiKeyException::class, null, false],
    '500' => [fn () => Http::response('oops', 500), ServerErrorException::class, null, true],
]);

it('turns network errors into a retryable exception', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    app(PixelforgeClient::class)->getGeneration('gen_1');
})->throws(ConnectionFailedException::class);

it('treats an unreadable success body as a server error', function () {
    Http::fake(['*' => Http::response(['nope' => true], 200)]);

    app(PixelforgeClient::class)->getGeneration('gen_1');
})->throws(ServerErrorException::class);

it('stops at its own request budget before Pixelforge rate limits us', function () {
    config(['pixelforge.limits.requests_per_minute' => 2]);
    Http::fake(['*' => Http::response(pixelforgeGeneration(), 200)]);
    $client = app(PixelforgeClient::class);

    $client->getGeneration('gen_1');
    $client->getGeneration('gen_1');

    expect(fn () => $client->getGeneration('gen_1'))->toThrow(RateLimitedException::class);
    Http::assertSentCount(2);
});

it('reads usage per day with exact costs', function () {
    Http::fake(['pf.test/v1/usage*' => Http::response([
        'object' => 'usage', 'start_date' => '2026-11-01', 'end_date' => '2026-11-02',
        'data' => [
            ['date' => '2026-11-01', 'generations' => 3, 'input_text_tokens' => 41, 'input_image_tokens' => 0, 'output_tokens' => 3300, 'reasoning_tokens' => 2950, 'cost_usd' => '0.169080000'],
            ['date' => '2026-11-02', 'generations' => 0, 'input_text_tokens' => 0, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0, 'cost_usd' => '0.000000000'],
        ],
        'total' => [],
    ])]);

    $days = app(PixelforgeClient::class)->getUsage(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-02'));

    expect($days)->toHaveKeys(['2026-11-01', '2026-11-02'])
        ->and($days['2026-11-01']->cost->nanos)->toBe(169_080_000)
        ->and($days['2026-11-01']->usage->outputTokens)->toBe(3300);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'start_date=2026-11-01&end_date=2026-11-02'));
});

it('falls back to the documented pricing when Pixelforge is down', function () {
    Http::fake(['*' => Http::response('down', 503)]);

    $catalog = app(PricingCatalogProvider::class)->current();

    expect($catalog->rates->toArray()['reasoning_tokens'])->toBe('12.50')
        ->and($catalog->outputTokens('high', '1536x1024'))->toBe(6450);
});

it('caches the pricing', function () {
    Http::fake(['*' => Http::response(pixelforgePricing())]);
    $provider = app(PricingCatalogProvider::class);

    $provider->current();
    $catalog = $provider->current();

    expect($catalog->textTokenVarianceBps)->toBe(1500);
    Http::assertSentCount(1);
});
