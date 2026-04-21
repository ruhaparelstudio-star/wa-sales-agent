<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_form_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->enum('form_type', ['inquiry', 'booking']);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'form_type', 'is_active']);
            $table->index(['tenant_id', 'form_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_form_templates');
    }
};
