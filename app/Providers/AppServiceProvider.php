<?php

namespace App\Providers;

use App\Modules\Subscription\Contracts\WhatsAppAgentCountContract;
use App\Modules\Tenancy\Http\Middleware\TenantResolverMiddleware;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->forceTestingConfiguration();

        $this->app->singleton(TenantContext::class);

        // Fallback binding — WhatsAppServiceProvider overrides this when the module loads.
        // Prevents boot failures if WhatsApp module is not registered.
        $this->app->bind(WhatsAppAgentCountContract::class, function () {
            return new class implements WhatsAppAgentCountContract {
                public function getConnectedCount(int $tenantId): int
                {
                    return DB::table('whatsapp_agents')
                        ->where('tenant_id', $tenantId)
                        ->where('status', 'connected')
                        ->count();
                }
            };
        });
    }

    public function boot(): void
    {
        if ($this->app->runningUnitTests()) {
            DB::purge();
        }

        Livewire::addPersistentMiddleware([
            TenantResolverMiddleware::class,
        ]);

        Factory::guessFactoryNamesUsing(function (string $modelName): string {
            return 'Database\\Factories\\' . class_basename($modelName) . 'Factory';
        });
    }

    private function forceTestingConfiguration(): void
    {
        if (! $this->app->runningUnitTests()) {
            return;
        }

        config([
            'app.env' => 'testing',
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
            'cache.default' => 'array',
            'session.driver' => 'array',
            'queue.default' => 'sync',
            'mail.default' => 'array',
        ]);
    }
}
