<?php

namespace App\Modules\Dashboard\Notifications;

use App\Modules\Conversations\Models\HandoffRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class HandoffCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private HandoffRequest $handoff;

    public function __construct(HandoffRequest $handoff)
    {
        $this->handoff = $handoff;
        $this->onQueue('medium');
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        $lead     = $this->handoff->lead;
        $leadName = $lead?->name ?? $lead?->phone_e164 ?? 'Unknown';
        $reason   = $this->handoff->reason->label();

        return [
            'type'             => 'handoff_created',
            'handoff_id'       => $this->handoff->id,
            'lead_id'          => $this->handoff->lead_id,
            'lead_name'        => $leadName,
            'reason'           => $this->handoff->reason->value,
            'reason_label'     => $reason,
            'message'          => "🤝 Handoff: {$leadName} butuh penanganan langsung — {$reason}",
            'url'              => route('leads.show', ['leadId' => $this->handoff->lead_id]),
            'conversation_url' => route('leads.show', ['leadId' => $this->handoff->lead_id]),
            'created_at'       => $this->handoff->created_at->toIso8601String(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $lead     = $this->handoff->lead;
        $leadName = $lead?->name ?? $lead?->phone_e164 ?? 'Unknown';

        return (new MailMessage)
            ->subject("[Wedding Agent] Lead {$leadName} butuh perhatian kamu")
            ->greeting('Halo!')
            ->line("Lead **{$leadName}** membutuhkan penanganan langsung.")
            ->line('Alasan: ' . $this->handoff->reason->value)
            ->when($this->handoff->reason_detail, fn ($m) => $m->line('Detail: ' . $this->handoff->reason_detail))
            ->action('Lihat Percakapan', route('leads.show', ['leadId' => $this->handoff->lead_id]))
            ->line('Segera tangani untuk menjaga pengalaman calon client.');
    }
}
