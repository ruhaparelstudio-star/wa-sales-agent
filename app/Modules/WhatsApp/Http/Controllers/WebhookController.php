<?php

namespace App\Modules\WhatsApp\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WhatsApp\Services\WebhookIngressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class WebhookController extends Controller
{
    public function __construct(
        private readonly WebhookIngressService $ingressService,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        // Validate secret header
        $secret = $request->header('X-Baileys-Secret');
        if (! $secret || $secret !== config('services.baileys.secret', env('BAILEYS_SECRET'))) {
            Log::warning('[Webhook] Invalid secret from ' . $request->ip());
            return response()->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $idempotencyKey = $request->header('X-Idempotency-Key', '');
        $payload = $request->json()->all();

        // Return 200 immediately — processing is async
        $this->ingressService->handle($payload, $idempotencyKey);

        return response()->json(['status' => 'ok']);
    }
}
