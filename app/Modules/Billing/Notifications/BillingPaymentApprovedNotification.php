<?php

namespace App\Modules\Billing\Notifications;

use App\Modules\Billing\Models\BillingInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BillingPaymentApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private BillingInvoice $invoice;

    public function __construct(BillingInvoice $invoice)
    {
        $this->invoice = $invoice;
        $this->onQueue('medium');
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type'           => 'billing_payment_approved',
            'invoice_id'     => $this->invoice->id,
            'invoice_number' => $this->invoice->invoice_number,
            'amount'         => $this->invoice->amount,
            'period_start'   => $this->invoice->period_start->toDateString(),
            'period_end'     => $this->invoice->period_end->toDateString(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $until = $this->invoice->period_end->format('d M Y');

        return (new MailMessage)
            ->subject("[Wedding Agent] Pembayaran dikonfirmasi — Langganan aktif sampai {$until}")
            ->greeting('Halo!')
            ->line('Pembayaran Anda telah dikonfirmasi. Terima kasih!')
            ->line('Nomor Invoice: ' . $this->invoice->invoice_number)
            ->line("Langganan aktif: {$this->invoice->period_start->format('d M Y')} – {$until}")
            ->action('Lihat Dashboard', url('/dashboard'))
            ->line('Selamat menggunakan Wedding Sales Agent.');
    }
}
