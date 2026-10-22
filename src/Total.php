<?php

namespace StickySoftware\Bump;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * One trackable's totals for the whole project.
 */
final readonly class Total
{
    /**
     * The amount in whole cents: 5994 for "59.94".
     */
    public int $cents;

    /**
     * @param  string  $amount  two decimals in the project currency, like "59.94"
     *
     * @throws InvalidArgumentException for an amount without two decimals, or too large to count in cents
     */
    public function __construct(
        public string $handle,
        public int $count,
        public string $amount,
        public string $currency,
        public ?CarbonImmutable $lastEventAt,
    ) {
        $this->cents = Amounts::toCents($amount);
    }
}
