<?php

namespace App\Modules\Conversations\Services;

use App\Modules\Conversations\Actions\PaymentProofDetectedAction;
use App\Modules\Conversations\Models\Message;
use App\Modules\Leads\Enums\LeadStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class MediaHandlerService
{
    public function __construct(
        private readonly PaymentProofDetectedAction $paymentProofDetectedAction,
    ) {}

    public function handleInboundMedia(Message $message, Tenant $tenant): void
    {
        if (! $message->media_url || ! $message->message_type->isMedia()) {
            return;
        }

        $localPath = $this->storagePath($tenant, $message);
        $directory = dirname($localPath);

        Storage::makeDirectory($directory);

        // Download media from WA provider URL
        try {
            $response = Http::timeout(30)->get($message->media_url);

            if ($response->successful()) {
                Storage::put($localPath, $response->body());
                $message->update(['media_url' => $localPath]);

                Log::info('[MediaHandler] Media saved', [
                    'message_id' => $message->id,
                    'path'       => $localPath,
                ]);
            } else {
                Log::warning('[MediaHandler] Failed to download media', [
                    'message_id' => $message->id,
                    'status'     => $response->status(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('[MediaHandler] Exception downloading media', [
                'message_id' => $message->id,
                'error'      => $e->getMessage(),
            ]);
        }

        // Payment proof detection: image/document + lead is HOT or READY_FOR_HUMAN
        if (
            in_array($message->message_type->value, ['image', 'document'], true)
            && in_array($message->lead->status, [LeadStatus::Hot, LeadStatus::ReadyForHuman], true)
        ) {
            $this->paymentProofDetectedAction->run($message, $tenant);
        }
    }

    public function storagePath(Tenant $tenant, Message $message): string
    {
        $ext   = $this->extensionFromMime($message->media_mime ?? '');
        $year  = now()->format('Y');
        $month = now()->format('m');
        $waId  = $message->wa_message_id ?? $message->id;

        return "tenants/{$tenant->id}/media/{$year}/{$month}/{$waId}.{$ext}";
    }

    private function extensionFromMime(string $mime): string
    {
        return match (true) {
            str_contains($mime, 'jpeg') || str_contains($mime, 'jpg') => 'jpg',
            str_contains($mime, 'png')  => 'png',
            str_contains($mime, 'gif')  => 'gif',
            str_contains($mime, 'webp') => 'webp',
            str_contains($mime, 'mp4')  => 'mp4',
            str_contains($mime, 'ogg')  => 'ogg',
            str_contains($mime, 'mp3') || str_contains($mime, 'mpeg') => 'mp3',
            str_contains($mime, 'pdf')  => 'pdf',
            str_contains($mime, 'webm') => 'webm',
            default                     => 'bin',
        };
    }
}
