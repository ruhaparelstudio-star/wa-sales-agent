<?php

namespace App\Modules\AgentCore\Providers;

use App\Modules\AgentCore\Contracts\LlmClientInterface;
use App\Modules\AgentCore\Services\LoggingLlmClient;
use App\Modules\AgentCore\Services\OpenAiLlmClient;
use Illuminate\Support\ServiceProvider;
use OpenAI;
use OpenAI\Contracts\ClientContract;

class AgentCoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ClientContract::class, function () {
            $apiKey = (string) config('services.openai.api_key', '');
            $org    = config('services.openai.organization');

            $factory = OpenAI::factory()->withApiKey($apiKey);
            if ($org) {
                $factory = $factory->withOrganization((string) $org);
            }

            return $factory->make();
        });

        $this->app->bind(OpenAiLlmClient::class, function ($app) {
            $configuredModel = (string) config('services.openai.model', '');
            $model = $configuredModel !== '' ? $configuredModel : OpenAiLlmClient::MODEL;

            return new OpenAiLlmClient(
                $app->make(ClientContract::class),
                $model,
            );
        });

        $this->app->bind(LlmClientInterface::class, function ($app) {
            return new LoggingLlmClient($app->make(OpenAiLlmClient::class));
        });
    }

    public function boot(): void {}
}
