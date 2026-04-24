<?php

namespace App\Modules\AgentCore\Support;

use RuntimeException;

final class OpenAiRuntimeConfig
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly array $config,
    ) {}

    public function enabled(): bool
    {
        $value = filter_var(
            $this->config['enabled'] ?? null,
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE,
        );

        return $value ?? false;
    }

    public function apiKey(): string
    {
        return trim((string) ($this->config['api_key'] ?? ''));
    }

    public function organization(): ?string
    {
        $organization = trim((string) ($this->config['organization'] ?? ''));

        return $organization !== '' ? $organization : null;
    }

    public function model(): string
    {
        $model = trim((string) ($this->config['model'] ?? ''));

        if ($model === '') {
            throw new RuntimeException('OPENAI_MODEL is missing from runtime configuration.');
        }

        return $model;
    }

    public function timeout(): int
    {
        return $this->integerSetting('timeout', 'OPENAI_TIMEOUT');
    }

    public function maxOutputTokens(): int
    {
        return $this->integerSetting('max_output_tokens', 'OPENAI_MAX_OUTPUT_TOKENS');
    }

    private function integerSetting(string $configKey, string $envKey): int
    {
        $rawValue = $this->config[$configKey] ?? null;

        if (! is_numeric($rawValue)) {
            throw new RuntimeException($envKey . ' is missing or invalid in runtime configuration.');
        }

        $value = (int) $rawValue;

        if ($value < 1) {
            throw new RuntimeException($envKey . ' must be greater than zero.');
        }

        return $value;
    }
}
