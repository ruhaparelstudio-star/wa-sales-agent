<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_outbound_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->uuid('whatsapp_agent_id');
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('recipient', 191);
            $table->string('message_type', 50)->default('text');
            $table->string('idempotency_key', 191)->unique();
            $table->string('payload_hash', 64)->nullable();
            $table->string('provider_message_id', 191)->nullable();
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending');
            $table->timestamp('sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->foreign('whatsapp_agent_id')->references('id')->on('whatsapp_agents')->cascadeOnDelete();
            $table->index(['whatsapp_agent_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_outbound_dispatches');
    }
};
