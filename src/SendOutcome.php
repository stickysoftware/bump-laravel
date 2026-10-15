<?php

namespace StickySoftware\Bump;

enum SendOutcome
{
    case Delivered;

    // Connection errors, 5xx and 429: the same batch can go again, its event ids make the repeat safe.
    case Retry;

    // 400, 401, 403 and anything else the API refuses: sending again changes nothing.
    case Failed;
}
