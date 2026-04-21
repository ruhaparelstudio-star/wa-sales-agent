<?php

namespace App\Modules\WhatsApp\Services;

use App\Modules\WhatsApp\Contracts\WhatsAppProviderInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class BaileysProvider implements WhatsAppProviderInterface
{
    private string $baseUrl;
    private string $secret;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.baileys.base_url', env('BAILEYS_BASE_URL', 'http://baileys-svc:3001')), '/');
        $this->secret = config('services.baileys.secret', env('BAILEYS_SECRET', ''));
    }

    public function startAgent(string $agentId): array
    {
        $response = $this->post("/agents/{$agentId}/start");
        return $response->json();
    }

    public function cancelPairing(string $agentId): void
    {
        $this->post("/agents/{$agentId}/cancel");
    }

    public function disconnectAgent(string $agentId): void
    {
        $this->post("/agents/{$agentId}/disconnect");
    }

    public function sendMessage(string $agentId, string $to, string $content, string $idempotencyKey): array
    {
        $response = $this->post("/agents/{$agentId}/send", [
            'to'              => $to,
            'type'            => 'text',
            'content'         => $content,
            'idempotency_key' => $idempotencyKey,
        ]);

        return $response->json();
    }

    public function sendDocument(string $agentId, string $to, string $filePath, string $filename, string $idempotencyKey, ?string $caption = null): array
    {
        $response = $this->post("/agents/{$agentId}/send", [
            'to'              => $to,
            'type'            => 'document',
            'content'         => $caption,
            'file_path'       => $this->normalizeDocumentPath($filePath),
            'filename'        => $filename,
            'idempotency_key' => $idempotencyKey,
        ]);

        return $response->json();
    }

    public function getAgentStatus(string $agentId): array
    {
        $response = Http::withHeaders($this->headers())
            ->timeout(10)
            ->get("{$this->baseUrl}/agents/{$agentId}/status");

        if ($response->failed()) {
            return ['status' => 'unknown'];
        }

        return $response->json();
    }

    public function getQrStreamUrl(string $agentId): string
    {
        // Return the public-facing URL via Nginx proxy
        $appUrl = rtrim(config('app.url'), '/');
        return "{$appUrl}/baileys/agents/{$agentId}/qr-stream";
    }

    private function headers(): array
    {
        return ['X-Baileys-Secret' => $this->secret];
    }

    private function normalizeDocumentPath(string $filePath): string
    {
        if ($filePath === '') {
            return $filePath;
        }

        if (str_starts_with($filePath, '/app/storage/')) {
            return $filePath;
        }

        $absolutePath = $this->isAbsolutePath($filePath)
            ? $filePath
            : Storage::path($filePath);

        $normalizedStorageRoot = $this->normalizePath(storage_path(''));
        $normalizedAbsolutePath = $this->normalizePath($absolutePath);

        if ($normalizedAbsolutePath === $normalizedStorageRoot) {
            return '/app/storage';
        }

        if (str_starts_with($normalizedAbsolutePath, $normalizedStorageRoot . '/')) {
            return '/app/storage' . substr($normalizedAbsolutePath, strlen($normalizedStorageRoot));
        }

        return $normalizedAbsolutePath;
    }

    private function post(string $path, array $body = [])
    {
        try {
            $response = Http::withHeaders($this->headers())
                ->timeout(15)
                ->post("{$this->baseUrl}{$path}", $body);

            if ($response->failed()) {
                $error = $response->json('error')
                    ?? $response->body()
                    ?? 'Unknown sidecar error';

                throw new RuntimeException("Baileys sidecar error on {$path}: HTTP {$response->status()} - {$error}");
            }

            return $response;
        } catch (ConnectionException $e) {
            throw new RuntimeException("Cannot reach Baileys sidecar: {$e->getMessage()}", 0, $e);
        }
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $path);
    }

    private function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
