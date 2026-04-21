<?php

namespace App\Modules\WhatsApp\Providers;

use App\Modules\Subscription\Contracts\WhatsAppAgentCountContract;
use App\Modules\WhatsApp\Contracts\WhatsAppProviderInterface;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use App\Modules\WhatsApp\Services\BaileysProvider;
use Illuminate\Support\ServiceProvider;

class WhatsAppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind provider interface to concrete Baileys implementation
        $this->app->bind(WhatsAppProviderInterface::class, BaileysProvider::class);

        // Override the temporary binding from AppServiceProvider with the real model
        $this->app->bind(WhatsAppAgentCountContract::class, function () {
            return new class implements WhatsAppAgentCountContract {
                public function getConnectedCount(int $tenantId): int
                {
                    return WhatsAppAgent::forTenant($tenantId)->connected()->count();
                }
            };
        });
    }

    public function boot(): void {}
}
