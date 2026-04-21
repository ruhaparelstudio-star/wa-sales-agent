<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private array $forwardMap = [
        'greeting' => 'new_lead',
        'discovery' => 'qualification',
        'needs_matching' => 'needs_discovery',
        'package_presentation' => 'package_recommendation',
        'soft_close' => 'closing',
        'handoff' => 'handoff_to_human',
    ];

    public function up(): void
    {
        foreach ($this->forwardMap as $from => $to) {
            DB::table('conversations')
                ->where('stage', $from)
                ->update([
                    'stage' => $to,
                    'stage_updated_at' => now(),
                ]);

            DB::table('conversation_states')
                ->where('current_stage', $from)
                ->update(['current_stage' => $to]);

            DB::table('conversation_stage_transitions')
                ->where('from_stage', $from)
                ->update(['from_stage' => $to]);

            DB::table('conversation_stage_transitions')
                ->where('to_stage', $from)
                ->update(['to_stage' => $to]);
        }
    }

    public function down(): void
    {
        foreach (array_flip($this->forwardMap) as $from => $to) {
            DB::table('conversations')
                ->where('stage', $from)
                ->update([
                    'stage' => $to,
                    'stage_updated_at' => now(),
                ]);

            DB::table('conversation_states')
                ->where('current_stage', $from)
                ->update(['current_stage' => $to]);

            DB::table('conversation_stage_transitions')
                ->where('from_stage', $from)
                ->update(['from_stage' => $to]);

            DB::table('conversation_stage_transitions')
                ->where('to_stage', $from)
                ->update(['to_stage' => $to]);
        }
    }
};
