<?php

namespace App\Http\Controllers;

use App\Services\Webhooks\InvalidSignatureException;
use App\Services\Webhooks\InvalidWebhookException;
use App\Services\Webhooks\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PixelforgeWebhookController extends Controller
{
    public function __construct(private readonly WebhookService $webhookService) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $result = $this->webhookService->handle(
                $request->getContent(),
                $request->header('Pixelforge-Signature'),
                $request->header('Pixelforge-Delivery-Attempt'),
            );
        } catch (InvalidSignatureException $e) {
            Log::warning('Rejected Pixelforge webhook', ['reason' => $e->getMessage(), 'event_id' => $request->header('Pixelforge-Event-Id')]);

            return response()->json(['error' => 'invalid_signature'], 400);
        } catch (InvalidWebhookException $e) {
            Log::warning('Unreadable Pixelforge webhook', ['reason' => $e->getMessage(), 'event_id' => $request->header('Pixelforge-Event-Id')]);

            return response()->json(['error' => 'invalid_payload'], 400);
        }

        return response()->json(['status' => $result]);
    }
}
