<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_pairings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('whatsapp_agent_id')->nullable();
            $table->foreign('whatsapp_agent_id')->references('id')->on('whatsapp_agents')->nullOnDelete();
            $table->enum('status', ['pending', 'completed', 'cancelled', 'expired'])->default('pending');
            $table->string('pairing_token', 64)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index('pairing_token');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_pairings');
    }
};
