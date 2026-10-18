<?php

namespace StickySoftware\Bump;

use InvalidArgumentException;

/**
 * @internal
 */
final class EventRules
{
    public const MAX_ID_LENGTH = 64;

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
}
