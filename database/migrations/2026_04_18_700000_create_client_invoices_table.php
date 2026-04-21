<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->uuid('whatsapp_agent_id')->nullable();
            $table->string('invoice_number');
            $table->enum('invoice_type', ['created', 'uploaded'])->default('created');
            $table->enum('status', ['draft', 'sent', 'delivered', 'viewed', 'paid', 'overdue', 'cancelled'])->default('draft');
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('currency', 3)->default('IDR');
            $table->date('due_date')->nullable();
            $table->string('pdf_path')->nullable();
            $table->text('intro_message')->nullable();
            $table->string('wa_message_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['tenant_id', 'invoice_number']);
            $table->index(['tenant_id', 'lead_id', 'status']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'due_date']);
            $table->foreign('whatsapp_agent_id')->references('id')->on('whatsapp_agents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_invoices');
    }
};
