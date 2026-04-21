<?php

namespace App\Modules\WhatsApp\Jobs;

use App\Modules\WhatsApp\Contracts\WhatsAppProviderInterface;
use App\Modules\WhatsApp\Enums\AgentStatus;
use App\Modules\WhatsApp\Enums\PairingStatus;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use App\Modules\WhatsApp\Models\WhatsAppPairing;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CleanupExpiredPairingsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(WhatsAppProviderInterface $provider): void
    {
        $expired = WhatsAppPairing::where('status', PairingStatus::Pending)
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expired as $pairing) {
            if ($pairing->whatsapp_agent_id) {
                try {
                    $provider->cancelPairing($pairing->whatsapp_agent_id);
                } catch (\Throwable) {
                    // Baileys may already have cleaned up the socket — continue anyway
                }

                WhatsAppAgent::where('id', $pairing->whatsapp_agent_id)
                    ->where('status', AgentStatus::Pending)
                    ->delete();
            }

            $pairing->update([
                'status' => PairingStatus::Expired,
                'cancelled_at' => now(),
            ]);
        }
    }
}
