<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->enum('direction', ['inbound', 'outbound']);
            $table->enum('message_type', ['text', 'image', 'video', 'audio', 'document', 'sticker', 'reaction', 'system'])->default('text');
            $table->text('content')->nullable();
            $table->string('media_url', 500)->nullable();
            $table->string('media_mime', 100)->nullable();
            $table->string('media_filename', 255)->nullable();
            $table->string('wa_message_id', 100)->nullable();
            $table->enum('status', ['pending', 'sent', 'delivered', 'read', 'failed'])->default('pending');
            $table->boolean('is_from_ai')->default(false);
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'direction', 'created_at']);
            $table->index(['lead_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
