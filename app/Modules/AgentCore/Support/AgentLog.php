<?php

namespace App\Modules\AgentCore\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AgentLog
{
    private const CHANNEL = 'agent';
    private const CONTEXT_KEY = 'trace_id';

    public static function newTraceId(): string
    {
        return (string) Str::ulid();
    }

    public static function bind(string $traceId): void
    {
        Log::withContext([self::CONTEXT_KEY => $traceId]);
    }

    public static function info(string $event, array $context = []): void
    {
        Log::channel(self::CHANNEL)->info($event, $context);
    }

    public static function warning(string $event, array $context = []): void
    {
        Log::channel(self::CHANNEL)->warning($event, $context);
    }

    public static function error(string $event, array $context = []): void
    {
        Log::channel(self::CHANNEL)->error($event, $context);
    }

    public static function debug(string $event, array $context = []): void
    {
        Log::channel(self::CHANNEL)->debug($event, $context);
    }
}
