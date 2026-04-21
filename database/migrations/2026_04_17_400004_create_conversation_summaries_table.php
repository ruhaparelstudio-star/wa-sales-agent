<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('conversation_id')->unique()->constrained('conversations')->cascadeOnDelete();
            $table->text('summary_text');
            $table->timestamp('last_summarized_at');
            $table->integer('message_count_at_summary')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'conversation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_summaries');
    }
};
