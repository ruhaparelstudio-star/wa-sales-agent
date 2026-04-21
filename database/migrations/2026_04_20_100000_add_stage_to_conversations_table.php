<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('stage', 40)->default('new_lead')->after('status');
            $table->timestamp('stage_updated_at')->nullable()->after('stage');
            $table->json('asked_fields')->nullable()->after('stage_updated_at');
            $table->string('next_expected_field', 64)->nullable()->after('asked_fields');
            $table->unsignedInteger('stage_transition_count')->default(0)->after('next_expected_field');

            $table->index(['tenant_id', 'stage']);
            $table->index(['tenant_id', 'stage_updated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'stage']);
            $table->dropIndex(['tenant_id', 'stage_updated_at']);
            $table->dropColumn([
                'stage',
                'stage_updated_at',
                'asked_fields',
                'next_expected_field',
                'stage_transition_count',
            ]);
        });
    }
};
