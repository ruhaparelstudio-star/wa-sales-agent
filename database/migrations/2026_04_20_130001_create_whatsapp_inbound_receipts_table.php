<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_inbound_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->uuid('whatsapp_agent_id');
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('wa_message_id', 191);
            $table->string('from_phone', 50)->nullable();
            $table->string('from_jid', 191)->nullable();
            $table->string('webhook_idempotency_key', 191)->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->enum('status', ['processing', 'processed', 'ignored', 'failed'])->default('processing');
            $table->string('quoted_wa_message_id', 191)->nullable();
            $table->string('quoted_from_jid', 191)->nullable();
            $table->text('quoted_content')->nullable();
            $table->timestamp('agent_core_dispatched_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->foreign('whatsapp_agent_id')->references('id')->on('whatsapp_agents')->cascadeOnDelete();
            $table->unique(['whatsapp_agent_id', 'wa_message_id'], 'wa_inbound_receipts_agent_message_unique');
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_inbound_receipts');
    }
};
