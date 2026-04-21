<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_booking_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->enum('form_type', ['inquiry', 'booking']);
            $table->string('field_key');
            $table->text('field_value')->nullable();
            $table->timestamps();

            $table->unique(['lead_id', 'form_type', 'field_key']);
            $table->index(['tenant_id', 'lead_id', 'form_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_booking_data');
    }
};
