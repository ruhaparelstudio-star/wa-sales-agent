<?php

use App\Modules\AgentCore\Jobs\FollowUpSchedulerJob;
use App\Modules\Billing\Jobs\GenerateRenewalInvoicesJob;
use App\Modules\Billing\Jobs\SendBillingAlertsJob;
use App\Modules\Dashboard\Jobs\SnapshotDashboardMetricsJob;
use App\Modules\Knowledge\Services\PricelistService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use App\Modules\WhatsApp\Jobs\CleanupExpiredPairingsJob;
use App\Modules\WhatsApp\Services\OutboundDispatchService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('whatsapp:debug-pricelist-send
    {tenant : Tenant ID or slug}
    {to : Target phone number or WhatsApp JID}
    {--agent= : Specific connected WhatsApp agent ID}
    {--caption=Pricelist terbaru kami : Document caption}
    {--no-caption : Send the document without any caption}
    {--follow-up= : Optional text sent after the PDF}
    {--no-follow-up : Skip any follow-up text}
', function (
    string $tenant,
    string $to,
    PricelistService $pricelistService,
    OutboundDispatchService $dispatchService,
): void {
    $tenantModel = Tenant::query()
        ->where('id', $tenant)
        ->orWhere('slug', $tenant)
        ->first();

    if (! $tenantModel) {
        $this->error("Tenant '{$tenant}' tidak ditemukan.");
        return;
    }

    $agentOption = $this->option('agent');
    $agentQuery = WhatsAppAgent::query()
        ->where('tenant_id', $tenantModel->id)
        ->connected();

    if (is_string($agentOption) && trim($agentOption) !== '') {
        $agentQuery->where('id', trim($agentOption));
    } else {
        $agentQuery->orderByDesc('is_default')
            ->orderByDesc('last_connected_at');
    }

    $agent = $agentQuery->first();

    if (! $agent) {
        $this->error('Agent WhatsApp connected tidak ditemukan untuk tenant ini.');
        return;
    }

    $pricelistPath = $pricelistService->findLatestPdf($tenantModel);

    if (! $pricelistPath) {
        $this->error('Pricelist PDF tidak ditemukan untuk tenant ini.');
        return;
    }

    $absolutePath = $pricelistService->absolutePath($pricelistPath);
    $filename = $pricelistService->filename($pricelistPath) ?? 'pricelist.pdf';
    $caption = $this->option('no-caption')
        ? null
        : (string) $this->option('caption');
    $followUp = $this->option('follow-up');

    $dispatchService->sendDocument(
        agent: $agent,
        to: $to,
        filePath: $absolutePath,
        filename: $filename,
        idempotencyKey: 'debug-pricelist-' . Str::uuid(),
        caption: is_string($caption) && $caption !== '' ? $caption : null,
    );

    if (! $this->option('no-follow-up') && is_string($followUp) && trim($followUp) !== '') {
        $dispatchService->send(
            agent: $agent,
            to: $to,
            content: trim($followUp),
            idempotencyKey: 'debug-follow-up-' . Str::uuid(),
        );
    }

    $this->info('Debug pricelist send executed.');
    $this->line("Tenant   : {$tenantModel->id} ({$tenantModel->slug})");
    $this->line("Agent    : {$agent->id}");
    $this->line("Target   : {$to}");
    $this->line("File     : {$absolutePath}");
    $this->line("Filename : {$filename}");
    $this->line('Caption  : ' . ($caption ?? '[none]'));
    $this->line('FollowUp : ' . ($this->option('no-follow-up') ? '[skipped]' : (is_string($followUp) && trim($followUp) !== '' ? trim($followUp) : '[none]')));
})->purpose('Send the latest tenant pricelist PDF directly to a WhatsApp recipient for debugging.');

// Daily 08:00 — generate renewal invoices for subscriptions expiring within 7 days
Schedule::job(GenerateRenewalInvoicesJob::class, 'low')->dailyAt('08:00');

// Daily 09:00 — send billing expiry alerts (H-7, H-3, H-1, H-0, H+1) with idempotency
Schedule::job(SendBillingAlertsJob::class, 'medium')->dailyAt('09:00');

// Every 15 minutes — fan-out follow-up messages to eligible leads
Schedule::job(FollowUpSchedulerJob::class, 'low')->everyFifteenMinutes();

// Every 30 minutes — pre-warm dashboard metrics cache for all active tenants
Schedule::job(SnapshotDashboardMetricsJob::class, 'low')->everyThirtyMinutes();

// Every 10 minutes — delete pending agents whose pairing window (10 min) has expired
Schedule::job(CleanupExpiredPairingsJob::class, 'low')->everyTenMinutes();
