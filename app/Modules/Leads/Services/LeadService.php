<?php

namespace App\Modules\Leads\Services;

use App\Modules\Leads\Enums\LeadStatus;
use App\Modules\Leads\Models\Lead;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use Illuminate\Support\Collection;

class LeadService
{
    public function findOrCreateByPhone(
        Tenant $tenant,
        string $phone,
        WhatsAppAgent $agent,
        ?string $whatsappJid = null,
    ): Lead
    {
        $normalizedPhone = $this->normalizePhone($phone);
        $normalizedJid   = $this->normalizeWhatsAppJid($whatsappJid);

        $lead = Lead::query()
            ->where('tenant_id', $tenant->id)
            ->where(function ($query) use ($normalizedPhone, $normalizedJid): void {
                if ($normalizedJid !== null) {
                    $query->where('whatsapp_jid', $normalizedJid);
                }

                if ($normalizedPhone !== null) {
                    $method = $normalizedJid !== null ? 'orWhere' : 'where';
                    $query->{$method}('phone_e164', $normalizedPhone);
                }
            })
            ->first();

        if (! $lead) {
            $lead = Lead::create([
                'tenant_id'          => $tenant->id,
                'whatsapp_agent_id'  => $agent->id,
                'phone_e164'         => $normalizedPhone ?? $phone,
                'whatsapp_jid'       => $normalizedJid,
                'status'             => LeadStatus::New,
            ]);
        }

        $updates = [];

        if ($lead->whatsapp_agent_id !== $agent->id) {
            $updates['whatsapp_agent_id'] = $agent->id;
        }

        if ($normalizedPhone !== null && $lead->phone_e164 !== $normalizedPhone) {
            $updates['phone_e164'] = $normalizedPhone;
        }

        if ($normalizedJid && $lead->whatsapp_jid !== $normalizedJid) {
            $updates['whatsapp_jid'] = $normalizedJid;
        }

        if ($updates !== []) {
            $lead->update($updates);
        }

        return $lead->refresh();
    }

    public function updateStatus(Lead $lead, LeadStatus $status): void
    {
        $lead->update(['status' => $status]);
    }

    public function pauseAutomation(Lead $lead): void
    {
        $lead->update(['automation_paused' => true]);
    }

    public function resumeAutomation(Lead $lead): void
    {
        $lead->update(['automation_paused' => false]);
    }

    public function getHotLeads(Tenant $tenant): Collection
    {
        return Lead::forTenant($tenant->id)->hot()->get();
    }

    private function normalizePhone(string $phone): ?string
    {
        if (str_contains($phone, '@')) {
            $phone = explode('@', $phone, 2)[0];
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return $digits !== '' ? '+' . $digits : null;
    }

    private function normalizeWhatsAppJid(?string $whatsappJid): ?string
    {
        if ($whatsappJid === null || trim($whatsappJid) === '') {
            return null;
        }

        return trim($whatsappJid);
    }
}
