<?php

namespace App\Modules\Billing\Notifications;

use App\Modules\Billing\Models\BillingInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BillingPaymentReceivedNotification extends Notification implements ShouldQueue
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
            'type'           => 'billing_payment_received',
            'invoice_id'     => $this->invoice->id,
            'invoice_number' => $this->invoice->invoice_number,
            'tenant_id'      => $this->invoice->tenant_id,
            'amount'         => $this->invoice->amount,
            'message'        => 'Bukti pembayaran diunggah. Menunggu verifikasi.',
            'approval_url'   => url('/admin/billing'),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $tenantName = $this->invoice->tenant->name ?? 'Tenant';

        return (new MailMessage)
            ->subject("[Wedding Agent] Bukti bayar baru dari {$tenantName}")
            ->greeting('Halo Admin!')
            ->line("Bukti pembayaran baru diterima dari {$tenantName}.")
            ->line('Nomor Invoice: ' . $this->invoice->invoice_number)
            ->line('Jumlah: Rp ' . number_format((float) $this->invoice->amount, 0, ',', '.'))
            ->action('Verifikasi Pembayaran', url('/admin/billing'))
            ->line('Segera verifikasi untuk mengaktifkan langganan tenant.');
    }
}
