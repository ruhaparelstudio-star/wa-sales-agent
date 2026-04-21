<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignUuid('whatsapp_agent_id')->nullable()->constrained('whatsapp_agents')->nullOnDelete();
            $table->enum('status', ['active', 'closed', 'handoff'])->default('active');
            $table->boolean('is_human_takeover')->default(false);
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'lead_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'whatsapp_agent_id']);
            $table->index(['tenant_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
