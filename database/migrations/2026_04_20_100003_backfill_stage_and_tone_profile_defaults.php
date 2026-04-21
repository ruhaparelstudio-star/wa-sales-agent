<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $defaultToneProfile = json_encode([
            'language_primary'  => 'id',
            'formality'         => 'semi_casual',
            'persona_style'     => 'consultative',
            'forbidden_phrases' => [],
        ]);

        DB::table('tenants')
            ->whereNull('tone_profile')
            ->update(['tone_profile' => $defaultToneProfile]);

        // Derive conversation stage from its status for rows stuck at default 'new_lead'.
        DB::table('conversations')
            ->where('stage', 'new_lead')
            ->where('status', 'closed')
            ->update([
                'stage'            => 'closed',
                'stage_updated_at' => now(),
            ]);

        DB::table('conversations')
            ->where('stage', 'new_lead')
            ->where('status', 'handoff')
            ->update([
                'stage'            => 'handoff_to_human',
                'stage_updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Irreversible by design: we cannot reliably distinguish backfilled rows from
        // legitimately default-valued ones. Re-running the stage migration down() drops
        // the columns entirely if a rollback is needed.
    }
};
