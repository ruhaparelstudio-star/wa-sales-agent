<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_turn_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('trace_id', 40)->index();

            $table->string('intent', 64)->nullable();
            $table->decimal('intent_confidence', 3, 2)->nullable();
            $table->json('extracted_slots')->nullable();

            $table->string('stage_before', 48)->nullable();
            $table->string('stage_after', 48)->nullable();
            $table->string('next_best_action', 64)->nullable();

            $table->boolean('fallback_used')->default(false);
            $table->string('fallback_reason', 128)->nullable();
            $table->string('tool_used', 64)->nullable();

            $table->string('response_type', 32)->nullable();
            $table->string('reply_excerpt', 500)->nullable();

            $table->json('evaluator_score')->nullable();
            $table->unsignedInteger('latency_ms_total')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['conversation_id', 'created_at']);
            $table->index(['fallback_used', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_turn_logs');
    }
};
