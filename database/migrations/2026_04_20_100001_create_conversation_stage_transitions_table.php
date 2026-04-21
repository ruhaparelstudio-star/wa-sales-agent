<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_stage_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->string('from_stage', 40);
            $table->string('to_stage', 40);
            $table->string('triggered_by', 20); // llm | rule | manual
            $table->string('reason', 160)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'conversation_id', 'created_at'], 'cst_tenant_conv_created_idx');
            $table->index(['tenant_id', 'to_stage', 'created_at'], 'cst_tenant_to_stage_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_stage_transitions');
    }
};
