<?php

namespace App\Modules\Dashboard\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class AgentSlotLimitNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private string $planName;
    private int $maxAgents;
    private int $currentConnected;

    public function __construct(string $planName, int $maxAgents, int $currentConnected)
    {
        $this->planName         = $planName;
        $this->maxAgents        = $maxAgents;
        $this->currentConnected = $currentConnected;
        $this->onQueue('low');
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type'              => 'agent_slot_limit',
            'plan_name'         => $this->planName,
            'max_agents'        => $this->maxAgents,
            'current_connected' => $this->currentConnected,
            'message'           => "📵 Batas slot agent tercapai ({$this->currentConnected}/{$this->maxAgents}) — upgrade paket {$this->planName}",
            'url'         => route('billing.index'),
            'upgrade_url' => route('billing.index'),
        ];
    }
}
