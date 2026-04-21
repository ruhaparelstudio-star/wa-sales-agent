<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('template_id')->constrained('booking_form_templates')->cascadeOnDelete();
            $table->string('field_key');
            $table->string('label');
            $table->enum('field_type', ['text', 'date', 'number', 'select', 'textarea']);
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'template_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_fields');
    }
};
