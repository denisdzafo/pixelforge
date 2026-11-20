<?php

use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Models\CreditTransaction;
use App\Models\WebhookEvent;

beforeEach(function () {
    config(['pixelforge.webhook_secret' => 'whsec_test']);
    $this->user = fundedUser('5.00');
    $this->generation = requestGeneration($this->user);
    $this->generation->forceFill(['pixelforge_id' => 'gen_abc', 'status' => GenerationStatus::Queued, 'rates' => pixelforgeGeneration()['rates']])->save();
    $this->remote = pixelforgeGeneration(['id' => 'gen_abc', 'metadata' => ['generation_id' => $this->generation->id, 'user_id' => (string) $this->user->id]]);
});

it('settles a succeeded generation exactly: hold released, actual cost charged', function () {
    sendWebhook($this, webhookEvent('generation.succeeded', pixelforgeSucceeded($this->remote)))->assertOk();

    $generation = $this->generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->provider_cost_nanos)->toBe(56_560_000)
        ->and($generation->charged_nanos)->toBe(70_700_000)
        ->and($generation->image_url)->toContain('gen_abc.png')
        ->and($generation->pixelforge_completed_at?->toIso8601String())->toBe('2026-11-04T14:03:29+00:00')
        ->and($this->user->refresh()->balance_nanos)->toBe(5_000_000_000 - 70_700_000);
});

it('records and charges a duplicate event only once', function () {
    $event = webhookEvent('generation.succeeded', pixelforgeSucceeded($this->remote), 'evt_dup');

    sendWebhook($this, $event)->assertOk()->assertJson(['status' => 'processed']);
    sendWebhook($this, $event)->assertOk()->assertJson(['status' => 'duplicate']);

    expect(WebhookEvent::query()->where('event_id', 'evt_dup')->count())->toBe(1)
        ->and(CreditTransaction::query()->where('generation_id', $this->generation->id)->where('type', CreditTransactionType::Charge)->count())->toBe(1)
        ->and($this->user->refresh()->balance_nanos)->toBe(5_000_000_000 - 70_700_000);
});

it('charges once when the same outcome arrives as two different events', function () {
    sendWebhook($this, webhookEvent('generation.succeeded', pixelforgeSucceeded($this->remote)))->assertOk();
    sendWebhook($this, webhookEvent('generation.succeeded', pixelforgeSucceeded($this->remote)))->assertOk();

    expect($this->user->refresh()->balance_nanos)->toBe(5_000_000_000 - 70_700_000);
});

it('keeps succeeded when a late processing event arrives', function () {
    sendWebhook($this, webhookEvent('generation.succeeded', pixelforgeSucceeded($this->remote)))->assertOk();
    sendWebhook($this, webhookEvent('generation.processing', array_replace($this->remote, ['status' => 'processing'])))->assertOk();

    expect($this->generation->refresh()->status)->toBe(GenerationStatus::Succeeded);
});

it('keeps failed when a succeeded event arrives afterwards, and charges nothing', function () {
    $failed = array_replace($this->remote, ['status' => 'failed', 'error' => ['code' => 'internal_error', 'message' => 'x'], 'completed_at' => '2026-11-04T14:03:29Z']);

    sendWebhook($this, webhookEvent('generation.failed', $failed))->assertOk();
    sendWebhook($this, webhookEvent('generation.succeeded', pixelforgeSucceeded($this->remote)))->assertOk();

    $generation = $this->generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->charged_nanos)->toBe(0)
        ->and($generation->error_code)->toBe('internal_error')
        ->and($this->user->refresh()->balance_nanos)->toBe(5_000_000_000);
});

it('makes a failed generation free: hold released, balance restored', function () {
    $failed = array_replace($this->remote, ['status' => 'failed', 'error' => ['code' => 'generation_failed', 'message' => 'x']]);

    sendWebhook($this, webhookEvent('generation.failed', $failed))->assertOk();

    expect($this->user->refresh()->balance_nanos)->toBe(5_000_000_000)
        ->and(CreditTransaction::query()->where('generation_id', $this->generation->id)->orderBy('id')->pluck('type')->map->value->all())
        ->toBe(['hold', 'hold_release']);
});

it('moves queued → processing', function () {
    sendWebhook($this, webhookEvent('generation.processing', array_replace($this->remote, ['status' => 'processing', 'started_at' => '2026-11-04T14:03:13Z'])))->assertOk();

    expect($this->generation->refresh()->status)->toBe(GenerationStatus::Processing)
        ->and($this->generation->pixelforge_started_at)->not->toBeNull();
});

it('matches a webhook that arrives before the create response via metadata', function () {
    $this->generation->forceFill(['pixelforge_id' => null, 'status' => GenerationStatus::Pending, 'rates' => null])->save();

    sendWebhook($this, webhookEvent('generation.processing', array_replace($this->remote, ['id' => 'gen_early', 'status' => 'processing'])))->assertOk();

    $generation = $this->generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Processing)
        ->and($generation->pixelforge_id)->toBe('gen_early')
        ->and($generation->rates)->not->toBeNull();
});

it('acknowledges a webhook for an unknown generation and stores it', function () {
    $other = pixelforgeGeneration(['id' => 'gen_unknown', 'status' => 'processing']);

    sendWebhook($this, webhookEvent('generation.processing', $other, 'evt_unknown'))->assertOk()->assertJson(['status' => 'ignored']);

    expect(WebhookEvent::query()->where('event_id', 'evt_unknown')->value('pixelforge_generation_id'))->toBe('gen_unknown');
});

it('answers ping', function () {
    sendWebhook($this, ['id' => 'evt_ping', 'object' => 'event', 'type' => 'ping', 'created_at' => '2026-11-04T14:03:29Z', 'data' => []])
        ->assertOk();
});

it('rejects bad signatures and stale timestamps with 400, recording nothing', function () {
    $event = webhookEvent('generation.succeeded', pixelforgeSucceeded($this->remote));

    sendWebhook($this, $event, secret: 'whsec_wrong')->assertStatus(400);
    sendWebhook($this, $event, timestamp: time() - 301)->assertStatus(400);

    expect(WebhookEvent::query()->count())->toBe(0)
        ->and($this->generation->refresh()->status)->toBe(GenerationStatus::Queued);
});

it('verifies the raw body, not re-encoded JSON', function () {
    $event = webhookEvent('generation.processing', array_replace($this->remote, ['status' => 'processing']));
    $body = json_encode($event, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    sendWebhook($this, $event, body: $body)->assertOk();
    expect($this->generation->refresh()->status)->toBe(GenerationStatus::Processing);
});

it('needs no CSRF token or session', function () {
    $this->withMiddleware();
    sendWebhook($this, webhookEvent('generation.processing', array_replace($this->remote, ['status' => 'processing'])))->assertOk();
});

it('never charges more than the hold', function () {
    sendWebhook($this, webhookEvent('generation.succeeded', pixelforgeSucceeded($this->remote, text: 100_000, reasoning: 100_000)))->assertOk();

    expect($this->generation->refresh()->charged_nanos)->toBe($this->generation->hold_nanos);
});

it('only processes an event again when the first attempt rolled back', function () {
    $event = webhookEvent('generation.succeeded', pixelforgeSucceeded($this->remote), 'evt_retry');
    WebhookEvent::query()->insert([
        'event_id' => 'evt_retry', 'type' => 'generation.succeeded', 'payload' => '{}', 'received_at' => now(),
    ]);

    sendWebhook($this, $event)->assertOk()->assertJson(['status' => 'processed']);

    expect($this->generation->refresh()->status)->toBe(GenerationStatus::Succeeded);
});
