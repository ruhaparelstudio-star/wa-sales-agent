<?php

namespace App\Modules\Dashboard\Notifications;

use App\Modules\Leads\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class HotLeadAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private Lead $lead;

    public function __construct(Lead $lead)
    {
        $this->lead = $lead;
        $this->onQueue('low');
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $leadName = $this->lead->name ?? $this->lead->phone_e164;
        $stage    = $this->lead->status->label();

        return [
            'type'      => 'hot_lead_alert',
            'lead_id'   => $this->lead->id,
            'lead_name' => $leadName,
            'phone'     => $this->lead->phone_e164,
            'stage'     => $this->lead->status->value,
            'message'   => "🔥 Lead {$leadName} siap ditangani — {$stage}",
            'url'      => route('leads.show', ['leadId' => $this->lead->id]),
            'lead_url' => route('leads.show', ['leadId' => $this->lead->id]),
        ];
    }
}
