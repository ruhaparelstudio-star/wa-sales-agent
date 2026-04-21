<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('conversation_id')->unique()->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->string('current_stage', 64)->nullable();
            $table->string('current_intent', 64)->nullable();
            $table->decimal('intent_confidence', 5, 2)->nullable();
            $table->string('interpretation_source', 32)->nullable();
            $table->string('lead_temperature', 16)->default('cold');
            $table->json('filled_slots')->nullable();
            $table->json('unresolved_questions')->nullable();
            $table->text('last_user_message')->nullable();
            $table->text('last_agent_message')->nullable();
            $table->text('last_agent_question')->nullable();
            $table->string('last_answered_topic', 64)->nullable();
            $table->string('next_best_action', 128)->nullable();
            $table->text('last_tool_result_summary')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'lead_id']);
            $table->index(['tenant_id', 'current_stage']);
            $table->index(['tenant_id', 'current_intent']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_states');
    }
};
