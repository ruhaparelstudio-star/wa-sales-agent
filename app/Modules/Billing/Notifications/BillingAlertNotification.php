<?php

namespace App\Modules\Billing\Notifications;

use App\Modules\Subscription\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BillingAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private Subscription $subscription;
    private int $daysRemaining;

    public function __construct(Subscription $subscription, int $daysRemaining)
    {
        $this->subscription   = $subscription;
        $this->daysRemaining  = $daysRemaining;
        $this->onQueue('medium');
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type'            => 'billing_alert',
            'subscription_id' => $this->subscription->id,
            'days_remaining'  => $this->daysRemaining,
            'ends_at'         => $this->subscription->ends_at->toDateString(),
            'message'         => $this->buildMessage(),
            'billing_url'     => url('/billing'),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('[Wedding Agent] ' . $this->buildSubject())
            ->greeting('Halo!')
            ->line($this->buildMessage())
            ->action('Bayar Sekarang', url('/billing'))
            ->line('Terima kasih telah menggunakan Wedding Sales Agent.');
    }

    private function buildMessage(): string
    {
        return match (true) {
            $this->daysRemaining === 7 => 'Langganan berakhir 7 hari lagi.',
            $this->daysRemaining === 3 => 'Langganan berakhir 3 hari lagi — segera perpanjang.',
            $this->daysRemaining === 1 => 'Langganan berakhir besok!',
            $this->daysRemaining === 0 => 'Langganan hari ini berakhir.',
            $this->daysRemaining < 0   => 'Langganan sudah expired — agent dinonaktifkan sementara.',
            default                    => "Langganan berakhir dalam {$this->daysRemaining} hari.",
        };
    }

    private function buildSubject(): string
    {
        return match (true) {
            $this->daysRemaining <= 0  => 'Langganan Expired',
            $this->daysRemaining === 1 => 'Langganan Berakhir Besok!',
            default                    => "Langganan Berakhir {$this->daysRemaining} Hari Lagi",
        };
    }
}
