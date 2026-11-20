<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\WebhookEvent;

class WebhookEventRepository
{
    /** @param array<mixed> $payload */
    public function insertIgnoringDuplicate(string $eventId, string $type, ?string $pixelforgeGenerationId, array $payload, ?int $deliveryAttempt): void
    {
        WebhookEvent::query()->insertOrIgnore([
            'event_id' => $eventId,
            'type' => $type,
            'pixelforge_generation_id' => $pixelforgeGenerationId,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'delivery_attempt' => $deliveryAttempt,
            'received_at' => now(),
        ]);
    }

    public function lockByEventId(string $eventId): WebhookEvent
    {
        return WebhookEvent::query()->where('event_id', $eventId)->lockForUpdate()->firstOrFail();
    }

    public function markProcessed(WebhookEvent $event): void
    {
        $event->processed_at = now();
        $event->save();
    }
}
