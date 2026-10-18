<?php

namespace StickySoftware\Bump;

/**
 * @internal
 */
enum SendOutcome
{
    case Delivered;

    // Connection errors, 5xx and 429. The event ids make a resend safe.
    case Retry;

    // Anything else the API refuses: sending again changes nothing.
    case Failed;
}
