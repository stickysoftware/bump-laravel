<?php

namespace StickySoftware\Bump;

use Carbon\CarbonImmutable;

/**
 * One trackable's totals for the whole project.
 */
final readonly class Total
{
    /**
     * @param  string  $amount  two decimals in the project currency, like "59.94"
     */
    public function __construct(
        public string $handle,
        public int $count,
        public string $amount,
        public string $currency,
        public ?CarbonImmutable $lastEventAt,
    ) {}
}
