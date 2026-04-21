<?php

namespace App\Modules\Tenancy\DTOs;

class ActivateInvitationDTO
{
    public function __construct(
        public readonly string $token,
        public readonly string $password,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            token: $data['token'],
            password: $data['password'],
        );
    }
}
