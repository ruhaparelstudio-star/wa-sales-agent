<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('lead_id')->unique()->constrained('leads')->cascadeOnDelete();
            $table->date('event_date')->nullable();
            $table->string('event_location')->nullable();
            $table->integer('budget_min')->nullable();
            $table->integer('budget_max')->nullable();
            $table->string('service_type')->nullable();
            $table->integer('guest_count')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'lead_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_profiles');
    }
};
