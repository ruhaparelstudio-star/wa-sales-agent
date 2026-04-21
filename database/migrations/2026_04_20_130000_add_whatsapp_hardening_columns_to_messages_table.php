<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->string('provider_idempotency_key', 191)->nullable()->after('wa_message_id');
            $table->string('quoted_wa_message_id', 191)->nullable()->after('provider_idempotency_key');
            $table->string('quoted_from_jid', 191)->nullable()->after('quoted_wa_message_id');
            $table->text('quoted_content')->nullable()->after('quoted_from_jid');

            $table->index(['direction', 'wa_message_id']);
            $table->index('provider_idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropIndex(['direction', 'wa_message_id']);
            $table->dropIndex(['provider_idempotency_key']);

            $table->dropColumn([
                'provider_idempotency_key',
                'quoted_wa_message_id',
                'quoted_from_jid',
                'quoted_content',
            ]);
        });
    }
};
