<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('leads')
            ->select(['id', 'tenant_id', 'phone_e164', 'whatsapp_jid'])
            ->whereNull('whatsapp_jid')
            ->where('phone_e164', 'like', '%@%')
            ->orderBy('id')
            ->chunkById(100, function ($leads): void {
                foreach ($leads as $lead) {
                    $parts = explode('@', (string) $lead->phone_e164, 2);
                    $localPart = $parts[0] ?? '';
                    $domain = $parts[1] ?? '';
                    $digits = preg_replace('/\D+/', '', $localPart) ?? '';

                    if ($digits === '' || $domain === '') {
                        continue;
                    }

                    $normalizedPhone = '+' . $digits;
                    $whatsappJid = $digits . '@' . $domain;

                    $canonicalLead = DB::table('leads')
                        ->where('tenant_id', $lead->tenant_id)
                        ->where('phone_e164', $normalizedPhone)
                        ->where('id', '!=', $lead->id)
                        ->orderBy('id')
                        ->first();

                    if ($canonicalLead) {
                        DB::table('leads')
                            ->where('id', $canonicalLead->id)
                            ->whereNull('whatsapp_jid')
                            ->update(['whatsapp_jid' => $whatsappJid]);

                        continue;
                    }

                    DB::table('leads')
                        ->where('id', $lead->id)
                        ->update([
                            'phone_e164' => $normalizedPhone,
                            'whatsapp_jid' => $whatsappJid,
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Data backfill is intentionally not reversed.
    }
};
