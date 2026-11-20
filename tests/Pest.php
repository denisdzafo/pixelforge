<?php

use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Models\User;
use App\Services\Credits\CreditLedger;
use App\Services\Generations\GenerationService;
use App\Services\Pricing\PricingCatalogProvider;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Unit');

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pixelforgeGeneration(array $overrides = []): array
{
    return array_replace([
        'id' => 'gen_'.strtoupper(bin2hex(random_bytes(8))),
        'object' => 'generation',
        'status' => 'queued',
        'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
        'size' => '1024x1024',
        'quality' => 'medium',
        'reference_images' => [],
        'metadata' => [],
        'image_url' => null,
        'image_url_expires_at' => null,
        'usage' => null,
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'error' => null,
        'created_at' => '2026-11-04T14:03:11Z',
        'started_at' => null,
        'completed_at' => null,
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $base
 * @return array<string, mixed>
 */
function pixelforgeSucceeded(array $base, int $text = 12, int $image = 0, int $output = 1100, int $reasoning = 1000, string $completedAt = '2026-11-04T14:03:29Z'): array
{
    return array_replace($base, [
        'status' => 'succeeded',
        'image_url' => 'https://pixelforge.promptedit.dev/images/'.$base['id'].'.png?expires=1&signature=x',
        'image_url_expires_at' => '2026-11-05T14:03:29Z',
        'usage' => [
            'input_text_tokens' => $text,
            'input_image_tokens' => $image,
            'output_tokens' => $output,
            'reasoning_tokens' => $reasoning,
            'total_tokens' => $text + $image + $output + $reasoning,
        ],
        'started_at' => '2026-11-04T14:03:13Z',
        'completed_at' => $completedAt,
    ]);
}

/** @return array<string, mixed> */
function pixelforgePricing(): array
{
    return [
        'object' => 'pricing',
        'currency' => 'usd',
        'unit' => 'per_1m_tokens',
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'tokens' => [
            'characters_per_text_token' => 4,
            'text_token_variance' => 0.15,
            'per_reference_image' => 765,
            'output' => [
                'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
            ],
            'reasoning' => [
                'low' => ['min' => 0, 'max' => 500],
                'medium' => ['min' => 200, 'max' => 2000],
                'high' => ['min' => 1000, 'max' => 8000],
            ],
        ],
    ];
}

function fundedUser(string $usd = '10.00', array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    if ($usd !== '0') {
        app(CreditLedger::class)->purchase($user, Money::fromUsd($usd), 'test-funds');
    }

    return $user->refresh();
}

/** @param array<string, mixed> $input */
function requestGeneration(User $user, array $input = []): Generation
{
    Queue::fake([SubmitGenerationJob::class]);
    Cache::put(PricingCatalogProvider::CACHE_KEY, pixelforgePricing(), 3600);

    return app(GenerationService::class)->submit($user, array_replace([
        'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
        'size' => '1024x1024',
        'quality' => 'medium',
        'reference_images' => [],
    ], $input));
}

/** @param array<string, mixed> $generation */
function webhookEvent(string $type, array $generation, ?string $eventId = null): array
{
    return [
        'id' => $eventId ?? 'evt_'.bin2hex(random_bytes(8)),
        'object' => 'event',
        'type' => $type,
        'created_at' => '2026-11-04T14:03:29Z',
        'data' => ['object' => $generation],
    ];
}

function sendWebhook(TestCase $test, array $event, ?string $secret = null, ?int $timestamp = null, ?string $body = null): TestResponse
{
    $body ??= json_encode($event, JSON_UNESCAPED_SLASHES);
    $timestamp ??= time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret ?? (string) config('pixelforge.webhook_secret'));

    return $test->call('POST', '/webhooks/pixelforge', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        'HTTP_PIXELFORGE_EVENT_ID' => $event['id'] ?? '',
        'HTTP_PIXELFORGE_EVENT_TYPE' => $event['type'] ?? '',
        'HTTP_PIXELFORGE_DELIVERY_ATTEMPT' => '1',
    ], content: $body);
}
