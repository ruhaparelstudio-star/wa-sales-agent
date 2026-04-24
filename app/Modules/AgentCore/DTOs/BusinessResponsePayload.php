<?php

namespace App\Modules\AgentCore\DTOs;

use App\Modules\AgentCore\DTOs\Concerns\SerializesDecisionContract;
use App\Modules\AgentCore\Enums\FinalAction;

final class BusinessResponsePayload
{
    use SerializesDecisionContract;

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $responseRules
     */
    public function __construct(
        public readonly string $payloadType,
        public readonly FinalAction $action,
        public readonly array $data,
        public readonly array $responseRules,
        public readonly string $schemaVersion = '1.0',
    ) {}

    /**
     * @return array{
     *     schema_version: string,
     *     payload_type: string,
     *     action: string,
     *     data: array<string, mixed>,
     *     response_rules: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'payload_type' => $this->payloadType,
            'action' => $this->action->value,
            'data' => $this->normalizeValue($this->data),
            'response_rules' => $this->normalizeValue($this->responseRules),
        ];
    }
}
