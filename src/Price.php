<?php

namespace Namingo\Cardo\DRS;

final readonly class Price
{
    public function __construct(
        public float $price,
        public bool $premium = false,
    ) {
    }
}
