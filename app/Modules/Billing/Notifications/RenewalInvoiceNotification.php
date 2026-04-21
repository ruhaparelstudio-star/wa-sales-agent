<?php

namespace App\Modules\Billing\Notifications;

use App\Modules\Billing\Models\BillingInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RenewalInvoiceNotification extends Notification implements ShouldQueue
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
            'type'           => 'renewal_invoice_generated',
            'invoice_id'     => $this->invoice->id,
            'invoice_number' => $this->invoice->invoice_number,
            'amount'         => $this->invoice->amount,
            'due_date'       => $this->invoice->due_date->toDateString(),
            'message'        => 'Invoice perpanjangan langganan telah dibuat.',
            'billing_url'    => url('/billing'),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('[Wedding Agent] Invoice Perpanjangan #' . $this->invoice->invoice_number)
            ->greeting('Halo!')
            ->line('Invoice perpanjangan langganan Anda telah dibuat.')
            ->line('Nomor Invoice: ' . $this->invoice->invoice_number)
            ->line('Jumlah: Rp ' . number_format((float) $this->invoice->amount, 0, ',', '.'))
            ->line('Jatuh Tempo: ' . $this->invoice->due_date->format('d M Y'))
            ->action('Bayar Sekarang', url('/billing'))
            ->line('Terima kasih telah menggunakan Wedding Sales Agent.');
    }
}
