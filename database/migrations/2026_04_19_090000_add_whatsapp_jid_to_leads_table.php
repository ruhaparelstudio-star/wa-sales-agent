<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('whatsapp_jid', 255)->nullable()->after('phone_e164');
            $table->index(['tenant_id', 'whatsapp_jid']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex('leads_tenant_id_whatsapp_jid_index');
            $table->dropColumn('whatsapp_jid');
        });
    }
};
