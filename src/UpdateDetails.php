<?php

namespace Namingo\Cardo\DRS;

final class UpdateDetails
{
    /**
     * @param bool|null $autoRenew Enable or disable automatic renewal
     * @param bool|null $privacy Enable or disable Whois privacy
     * @param bool|null $locked Set the transfer lock status
     */
    public function __construct(
        public ?bool $autoRenew = null,
        public ?bool $privacy = null,
        public ?bool $locked = null,
    ) {
    }
}