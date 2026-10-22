<?php

namespace StickySoftware\Bump;

use InvalidArgumentException;

/**
 * @internal
 */
final class Amounts
{
    /**
     * An int, or a string without decimals, goes out untouched: it could be cents, so Bump refuses it
     * and the project shows it as a problem. So does anything that isn't a number.
     *
     * @throws InvalidArgumentException for an amount Bump would have to round
     */
    public static function encode(mixed $amount): mixed
    {
        if (is_array($amount)) {
            return array_map(self::encodeOne(...), $amount);
        }

        return self::encodeOne($amount);
    }

    /**
     * @return string|array<string>
     *
     * @throws InvalidArgumentException for a value that isn't a whole number
     */
    public static function fromCents(mixed $cents): string|array
    {
        if (is_array($cents)) {
            return array_map(self::fromCentsOne(...), $cents);
        }

        return self::fromCentsOne($cents);
    }

    /**
     * @throws InvalidArgumentException for text that isn't two decimals, or too large for an int
     */
    public static function toCents(string $amount): int
    {
        if (preg_match('/^(-?)(\d+)\.(\d{2})$/', $amount, $parts) !== 1) {
            throw new InvalidArgumentException("{$amount} isn't an amount with two decimals.");
        }

        $digits = ltrim($parts[2].$parts[3], '0');

        if ($digits === '') {
            return 0;
        }

        $cents = filter_var($parts[1].$digits, FILTER_VALIDATE_INT);

        if ($cents === false) {
            throw new InvalidArgumentException("{$amount} is too large to count in cents.");
        }

        return $cents;
    }

    private static function encodeOne(mixed $amount): mixed
    {
        return match (true) {
            is_float($amount) => self::fromFloat($amount),
            is_string($amount) => self::fromString($amount),
            default => $amount,
        };
    }

    /**
     * Read the way Bump reads a float, through %.15g: 59.97000000000001 is 59.97, while
     * 49.999 has a real third decimal. Never rounded.
     */
    private static function fromFloat(float $amount): string
    {
        if (! is_finite($amount)) {
            throw new InvalidArgumentException('amount takes a number like 49.99, got '.var_export($amount, true).'.');
        }

        $text = sprintf('%.15g', $amount);

        if (preg_match('/^-?\d+$/', $text) === 1) {
            $text .= '.0';
        }

        if (preg_match('/^-?\d+\.\d+$/', $text) === 1) {
            $decimal = self::twoDecimals($text, $amount);

            return $decimal === '-0.00' ? '0.00' : $decimal;
        }

        // %.15g writes 1e15 and up with an exponent. Bump refuses those as too large, so a whole one
        // goes out for Bump to say so.
        if (floor($amount) === $amount && abs($amount) >= 1) {
            return sprintf('%.0F', $amount).'.00';
        }

        throw self::tooPrecise($amount);
    }

    private static function fromString(string $amount): string
    {
        if (preg_match('/^-?\d+\.\d+$/', $amount) !== 1) {
            return $amount;
        }

        return self::twoDecimals($amount, $amount);
    }

    /**
     * Trailing zeros past the second decimal lose nothing, so "49.9900" from a decimal(10,4) column is "49.99".
     */
    private static function twoDecimals(string $decimal, float|string $given): string
    {
        [$whole, $fraction] = explode('.', $decimal);
        $fraction = rtrim($fraction, '0');

        if (strlen($fraction) > 2) {
            throw self::tooPrecise($given);
        }

        return $whole.'.'.str_pad($fraction, 2, '0');
    }

    private static function fromCentsOne(mixed $cents): string
    {
        if (! is_int($cents)) {
            throw new InvalidArgumentException('cents takes whole numbers, got '.get_debug_type($cents).'.');
        }

        // Text, not division: -9223372036854775808 has no positive int.
        $digits = str_pad(ltrim((string) $cents, '-'), 3, '0', STR_PAD_LEFT);
        $sign = $cents < 0 ? '-' : '';

        return $sign.substr($digits, 0, -2).'.'.substr($digits, -2);
    }

    private static function tooPrecise(float|string $amount): InvalidArgumentException
    {
        return new InvalidArgumentException('Amounts take at most 2 decimals, got '.var_export($amount, true).'.');
    }
}
