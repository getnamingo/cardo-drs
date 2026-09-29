<?php

namespace Namingo\Cardo\DRS;

use DateTime;

final readonly class TransferStatus
{
    public function __construct(
        public TransferStatusEnum $status,
        public ?string $reason = null,
        public ?DateTime $timestamp = null,
    ) {
    }
}
