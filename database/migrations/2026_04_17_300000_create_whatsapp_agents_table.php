<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_agents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('phone_number', 20)->nullable();
            $table->string('display_name')->nullable();
            $table->enum('status', ['pending', 'connected', 'disconnected'])->default('pending');
            $table->boolean('is_default')->default(false);
            $table->timestamp('last_connected_at')->nullable();
            $table->timestamp('last_disconnected_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'phone_number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_agents');
    }
};
