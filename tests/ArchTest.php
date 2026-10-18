<?php

// Package-wide rules: no debugging leftovers in src/, final stays final, and the fake stays out of production code.

arch('src has no debugging leftovers')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'die'])
    ->not->toBeUsed()
    ->ignoring('StickySoftware\Bump\Tests');

arch('classes are final, except the provider, facade, command and jobs')
    ->expect('StickySoftware\Bump')
    ->classes()
    ->toBeFinal()
    ->ignoring([
        'StickySoftware\Bump\BumpServiceProvider',
        'StickySoftware\Bump\Console\BackfillCommand',
        'StickySoftware\Bump\Facades\Bump',
        'StickySoftware\Bump\Jobs\ForgetIdentifiable',
        'StickySoftware\Bump\Jobs\SendEvents',
        'StickySoftware\Bump\Tests',
    ]);

arch('the testing fake is only used from tests and the facade')
    ->expect('StickySoftware\Bump\Testing')
    ->toOnlyBeUsedIn([
        'StickySoftware\Bump\Testing',
        'StickySoftware\Bump\Facades\Bump',
        'StickySoftware\Bump\Tests',
    ]);
