<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Repositories\WebhookEventRepository;
use App\Services\Generations\GenerationStateMachine;
use App\Services\Pixelforge\Data\GenerationData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class WebhookService
{
    public function __construct(
        private readonly WebhookEventRepository $events,
        private readonly GenerationStateMachine $stateMachine,
    ) {}

    public function handle(string $rawBody, ?string $signatureHeader, ?string $deliveryAttemptHeader): string
    {
        WebhookSignatureVerifier::fromConfig()->verify($rawBody, $signatureHeader);

        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            throw new InvalidWebhookException('Webhook body is not a JSON object');
        }

        $eventId = is_string($payload['id'] ?? null) ? $payload['id'] : '';
        $type = is_string($payload['type'] ?? null) ? $payload['type'] : '';
        if ($eventId === '' || $type === '') {
            throw new InvalidWebhookException('Webhook without an event id or type');
        }

        $generation = null;
        $object = $payload['data']['object'] ?? null;
        if (str_starts_with($type, 'generation.') && is_array($object)) {
            try {
                $generation = GenerationData::fromArray($object);
            } catch (InvalidArgumentException $e) {
                throw new InvalidWebhookException($e->getMessage(), previous: $e);
            }
        }

        $attempt = is_numeric($deliveryAttemptHeader) ? (int) $deliveryAttemptHeader : null;

        return DB::transaction(function () use ($eventId, $type, $payload, $attempt, $generation) {
            $this->events->insertIgnoringDuplicate($eventId, $type, $generation?->id, $payload, $attempt);

            $event = $this->events->lockByEventId($eventId);
            if ($event->processed_at !== null) {
                Log::info('Duplicate webhook ignored', ['event_id' => $eventId, 'delivery_attempt' => $attempt]);

                return 'duplicate';
            }

            $result = 'ignored';
            if ($generation !== null) {
                $result = $this->stateMachine->apply($generation, "webhook:{$type}") === null ? 'ignored' : 'processed';
            } elseif ($type !== 'ping') {
                Log::info('Unhandled webhook type', ['event_id' => $eventId, 'type' => $type]);
            }

            $this->events->markProcessed($event);

            return $result;
        });
    }
}
