<?php

namespace Namingo\Cardo\DRS;

use DateTime;

final readonly class Renewal
{
    public function __construct(
        public ?string $orderId = null,
        public ?DateTime $expiresAt = null,
    ) {
    }
}
