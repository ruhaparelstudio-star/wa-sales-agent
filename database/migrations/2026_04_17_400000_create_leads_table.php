<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('whatsapp_agent_id')->nullable()->constrained('whatsapp_agents')->nullOnDelete();
            $table->string('phone_e164', 20);
            $table->string('name')->nullable();
            $table->enum('status', ['new', 'qualified', 'interested', 'hot', 'ready_for_human', 'closed_won', 'closed_lost'])->default('new');
            $table->tinyInteger('interest_score')->default(0);
            $table->tinyInteger('risk_score')->default(0);
            $table->boolean('automation_paused')->default(false);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'phone_e164']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'last_message_at']);
            $table->index(['tenant_id', 'whatsapp_agent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
