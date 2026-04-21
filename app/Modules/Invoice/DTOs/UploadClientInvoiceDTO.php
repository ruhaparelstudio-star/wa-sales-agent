<?php

namespace App\Modules\Invoice\DTOs;

use Illuminate\Http\UploadedFile;

class UploadClientInvoiceDTO
{
    public function __construct(
        public readonly int          $leadId,
        public readonly UploadedFile $file,
        public readonly ?string      $dueDate,
        public readonly ?string      $introMessage,
    ) {}
}
