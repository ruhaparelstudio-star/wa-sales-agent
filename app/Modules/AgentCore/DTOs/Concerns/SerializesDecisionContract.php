<?php

namespace App\Modules\AgentCore\DTOs\Concerns;

use BackedEnum;
use JsonException;

trait SerializesDecisionContract
{
    /**
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;

    /**
     * @throws JsonException
     */
    public function toJson(int $flags = 0): string
    {
        return json_encode($this->toArray(), $flags | JSON_THROW_ON_ERROR);
    }

    protected function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            return $value->toArray();
        }

        if (! is_array($value)) {
            return $value;
        }

        $normalized = [];

        foreach ($value as $key => $item) {
            $normalized[$key] = $this->normalizeValue($item);
        }

        return $normalized;
    }
}
