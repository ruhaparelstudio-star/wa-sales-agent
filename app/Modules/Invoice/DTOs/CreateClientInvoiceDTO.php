<?php

namespace App\Modules\Invoice\DTOs;

class CreateClientInvoiceDTO
{
    public function __construct(
        public readonly int     $leadId,
        public readonly array   $items,
        public readonly ?string $dueDate,
        public readonly ?string $introMessage,
        public readonly ?string $notes,
    ) {}
}
