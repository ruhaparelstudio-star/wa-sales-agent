<?php

namespace App\Modules\Conversations\Services;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ConversationLockService
{
    public function blockForInbound(
        string $agentId,
        ?string $fromJid,
        string $fromPhone,
        Closure $callback,
        int $seconds = 20,
        int $waitSeconds = 5,
    ): mixed {
        $party = trim((string) ($fromJid ?: $fromPhone));

        return $this->block(
            sprintf('conversation-lock:inbound:%s:%s', $agentId, sha1($party)),
            $callback,
            $seconds,
            $waitSeconds,
        );
    }

    public function blockForConversation(
        int|string $conversationId,
        Closure $callback,
        int $seconds = 30,
        int $waitSeconds = 10,
    ): mixed {
        return $this->block(
            'conversation-lock:conversation:' . $conversationId,
            $callback,
            $seconds,
            $waitSeconds,
        );
    }

    public function blockForOutbound(string $idempotencyKey, Closure $callback, int $seconds = 20, int $waitSeconds = 5): mixed
    {
        return $this->block(
            'conversation-lock:outbound:' . sha1($idempotencyKey),
            $callback,
            $seconds,
            $waitSeconds,
        );
    }

    private function block(string $key, Closure $callback, int $seconds, int $waitSeconds): mixed
    {
        if (! method_exists(Cache::getFacadeRoot(), 'lock')) {
            return $callback();
        }

        try {
            return Cache::lock($key, $seconds)->block($waitSeconds, $callback);
        } catch (LockTimeoutException) {
            return $callback();
        } catch (Throwable) {
            return $callback();
        }
    }
}
