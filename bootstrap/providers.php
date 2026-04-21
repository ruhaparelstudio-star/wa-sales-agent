<?php

use App\Modules\AgentCore\Providers\AgentCoreServiceProvider;
use App\Modules\WhatsApp\Providers\WhatsAppServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    WhatsAppServiceProvider::class,
    AgentCoreServiceProvider::class,
    App\Modules\Dashboard\Providers\DashboardServiceProvider::class,
];
