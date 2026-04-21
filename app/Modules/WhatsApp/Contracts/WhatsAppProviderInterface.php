<?php

namespace App\Modules\WhatsApp\Contracts;

interface WhatsAppProviderInterface
{
    public function startAgent(string $agentId): array;

    public function cancelPairing(string $agentId): void;

    public function disconnectAgent(string $agentId): void;

    public function sendMessage(string $agentId, string $to, string $content, string $idempotencyKey): array;

    public function sendDocument(string $agentId, string $to, string $filePath, string $filename, string $idempotencyKey, ?string $caption = null): array;

    public function getAgentStatus(string $agentId): array;

    public function getQrStreamUrl(string $agentId): string;
}
