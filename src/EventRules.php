<?php

namespace StickySoftware\Bump;

use InvalidArgumentException;

/**
 * @internal
 */
final class EventRules
{
    public const MAX_ID_LENGTH = 64;

    public const INVALID_UTF8 = "Bump dropped an event with text that isn't valid UTF-8.";

    /**
     * @throws InvalidArgumentException with the reason the event can't be sent
     */
    public static function check(?string $id, mixed $count, mixed $amount): void
    {
        if ($id === '') {
            throw new InvalidArgumentException('The event id is empty.');
        }

        if ($id !== null && mb_strlen($id) > self::MAX_ID_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'The event id is %d characters, Bump takes at most %d.',
                mb_strlen($id),
                self::MAX_ID_LENGTH,
            ));
        }

        if (is_array($count) && is_array($amount)) {
            throw new InvalidArgumentException("count and amount can't both be lists.");
        }
    }

    /**
     * @throws InvalidArgumentException for an empty handle or one that isn't valid UTF-8
     */
    public static function checkTrackable(string $trackable): void
    {
        if ($trackable === '') {
            throw new InvalidArgumentException('An empty handle names no trackable.');
        }

        if (! mb_check_encoding($trackable, 'UTF-8')) {
            throw new InvalidArgumentException("The handle isn't valid UTF-8.");
        }
    }

    /**
     * Every string, keys included: one invalid byte fails the JSON for the whole batch. Bytes are never
     * replaced, since a changed handle or id would count as a different one.
     */
    public static function validUtf8(mixed $value): bool
    {
        if (is_string($value)) {
            return mb_check_encoding($value, 'UTF-8');
        }

        if (! is_array($value)) {
            return true;
        }

        foreach ($value as $key => $item) {
            if (! self::validUtf8($key) || ! self::validUtf8($item)) {
                return false;
            }
        }

        return true;
    }
}
