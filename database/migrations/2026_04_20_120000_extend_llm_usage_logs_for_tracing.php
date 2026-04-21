<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE llm_usage_logs MODIFY COLUMN mode ENUM('classifier','response','summary','follow_up','evaluation') NOT NULL");
        }

        Schema::table('llm_usage_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('message_id')->nullable()->after('conversation_id');
            $table->string('trace_id', 40)->nullable()->after('message_id');
            $table->unsignedInteger('latency_ms')->nullable()->after('total_tokens');
            $table->string('prompt_hash', 40)->nullable()->after('latency_ms');
            $table->string('response_excerpt', 500)->nullable()->after('prompt_hash');

            $table->index('trace_id');
            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('llm_usage_logs', function (Blueprint $table) {
            $table->dropIndex(['trace_id']);
            $table->dropIndex(['conversation_id', 'created_at']);
            $table->dropColumn(['message_id', 'trace_id', 'latency_ms', 'prompt_hash', 'response_excerpt']);
        });
    }
};
