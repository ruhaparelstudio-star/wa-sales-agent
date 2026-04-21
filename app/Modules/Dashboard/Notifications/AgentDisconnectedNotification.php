<?php

namespace App\Modules\Dashboard\Notifications;

use App\Modules\WhatsApp\Models\WhatsAppAgent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AgentDisconnectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private WhatsAppAgent $agent;
    private string $reason;

    public function __construct(WhatsAppAgent $agent, string $reason)
    {
        $this->agent  = $agent;
        $this->reason = $reason;
        $this->onQueue('medium');
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        $phone = $this->agent->phone_number ?? $this->agent->id;

        return [
            'type'            => 'agent_disconnected',
            'agent_id'        => $this->agent->id,
            'phone_number'    => $this->agent->phone_number,
            'reason'          => $this->reason,
            'message'         => "⚠️ Nomor WA {$phone} terputus — {$this->reason}",
            'url'             => route('whatsapp-agents.index'),
            'disconnected_at' => now()->toIso8601String(),
            'reconnect_url'   => route('whatsapp-agents.index'),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $phone = $this->agent->phone_number ?? $this->agent->id;

        return (new MailMessage)
            ->subject("[Wedding Agent] Nomor WA {$phone} terputus")
            ->greeting('Halo!')
            ->line("Nomor WhatsApp **{$phone}** terputus dari sistem.")
            ->line('Alasan: ' . $this->reason)
            ->line('Agent tidak dapat menerima pesan baru hingga tersambung kembali.')
            ->action('Sambung Ulang', url('/whatsapp/agents'))
            ->line('Segera lakukan reconnect untuk menghindari kehilangan lead.');
    }
}
