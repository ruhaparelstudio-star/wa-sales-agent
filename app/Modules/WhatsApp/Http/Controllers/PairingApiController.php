<?php

namespace App\Modules\WhatsApp\Http\Controllers;

use App\Modules\Tenancy\Services\TenantContext;
use App\Modules\WhatsApp\Models\WhatsAppPairing;
use App\Modules\WhatsApp\Services\PairingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PairingApiController extends Controller
{
    public function __construct(
        private readonly PairingService $pairingService,
        private readonly TenantContext $tenantContext,
    ) {}

    public function stream(string $pairingId): StreamedResponse
    {
        $pairing = WhatsAppPairing::findOrFail($pairingId);

        abort_if($pairing->tenant_id !== $this->tenantContext->getTenantId(), 403);

        $pairingService = $this->pairingService;
        $openStream     = fn (string $id) => $this->openBaileysQrStream($id);
        $parsePayload   = fn (string $block) => $this->parseSsePayload($block);

        return response()->stream(function () use ($pairing, $pairingService, $openStream, $parsePayload) {
            set_time_limit(0);

            $startedAt = now();
            $lastQr    = null;
            $stream    = $openStream((string) $pairing->whatsapp_agent_id);
            $buffer    = '';

            if (! is_resource($stream)) {
                echo 'data: ' . json_encode(['type' => 'error', 'message' => 'Cannot reach pairing service']) . "\n\n";
                ob_flush();
                flush();
                return;
            }

            while (true) {
                if (connection_aborted()) {
                    break;
                }

                if ($startedAt->diffInMinutes(now()) >= 10) {
                    echo "event: session_cancelled\ndata: {}\n\n";
                    ob_flush();
                    flush();
                    break;
                }

                $pairing = WhatsAppPairing::find($pairing->id);

                if (! $pairing || $pairing->status->value === 'cancelled') {
                    echo 'data: ' . json_encode(['type' => 'session_cancelled']) . "\n\n";
                    ob_flush();
                    flush();
                    break;
                }

                if ($pairing->status->value === 'completed') {
                    echo 'data: ' . json_encode([
                        'type' => 'agent_connected',
                        'agent_id' => $pairing->whatsapp_agent_id,
                    ]) . "\n\n";
                    ob_flush();
                    flush();
                    break;
                }

                if (is_resource($stream)) {
                    $chunk = stream_get_contents($stream);

                    if ($chunk !== false && $chunk !== '') {
                        $buffer .= $chunk;

                        while (($separatorPos = strpos($buffer, "\n\n")) !== false) {
                            $eventBlock = substr($buffer, 0, $separatorPos);
                            $buffer = substr($buffer, $separatorPos + 2);

                            $payload = $parsePayload($eventBlock);
                            if ($payload === null) {
                                continue;
                            }

                            if (($payload['type'] ?? null) === 'qr' && ($payload['qr'] ?? null) !== $lastQr) {
                                $lastQr = $payload['qr'];
                                echo 'data: ' . json_encode($payload) . "\n\n";
                                ob_flush();
                                flush();
                            }

                            if (($payload['type'] ?? null) === 'agent_connected') {
                                $phoneNumber = $payload['phone_number'] ?? '';
                                $pairingService->completePairing(
                                    (string) $pairing->whatsapp_agent_id,
                                    $phoneNumber
                                );
                                echo 'data: ' . json_encode($payload) . "\n\n";
                                ob_flush();
                                flush();
                                break 2;
                            }
                        }
                    }
                }

                echo ": heartbeat\n\n";
                ob_flush();
                flush();

                usleep(250000);
            }

            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ]);
    }

    public function cancel(Request $request, string $pairingId): JsonResponse
    {
        $pairing = WhatsAppPairing::findOrFail($pairingId);

        abort_if($pairing->tenant_id !== $this->tenantContext->getTenantId(), 403);

        $this->pairingService->cancelPairing($pairing);

        return response()->json(['status' => 'cancelled']);
    }

    private function openBaileysQrStream(string $agentId)
    {
        $baseUrl = rtrim(config('services.baileys.base_url', 'http://baileys-svc:3001'), '/');
        $secret = config('services.baileys.secret', '');

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", [
                    'Accept: text/event-stream',
                    "X-Baileys-Secret: {$secret}",
                ]),
                'timeout' => 60,
                'ignore_errors' => true,
            ],
        ]);

        $stream = @fopen("{$baseUrl}/agents/{$agentId}/qr-stream", 'r', false, $context);

        if (is_resource($stream)) {
            stream_set_blocking($stream, false);
        }

        return $stream;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseSsePayload(string $eventBlock): ?array
    {
        $dataLines = [];

        foreach (preg_split('/\r?\n/', $eventBlock) as $line) {
            if (str_starts_with($line, 'data:')) {
                $dataLines[] = trim(substr($line, 5));
            }
        }

        if ($dataLines === []) {
            return null;
        }

        $decoded = json_decode(implode("\n", $dataLines), true);

        return is_array($decoded) ? $decoded : null;
    }
}
