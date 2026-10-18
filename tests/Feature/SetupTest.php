<?php

// What an app gets from the defaults: the API URL, BUMP_ENABLED under testing, missing-token logging, the User-Agent and the class behind the facade.

use Composer\InstalledVersions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use StickySoftware\Bump\Delivery;
use StickySoftware\Bump\Facades\Bump;
use StickySoftware\Bump\Tracker;

/**
 * @param  array<string, string|null>  $variables
 * @return array<string, mixed>
 */
function configWithEnv(array $variables): array
{
    $previous = [];

    foreach ($variables as $name => $value) {
        $previous[$name] = $_SERVER[$name] ?? null;

        if ($value === null) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);
        } else {
            $_SERVER[$name] = $_ENV[$name] = $value;
            putenv("{$name}={$value}");
        }
    }

    try {
        return require __DIR__.'/../../config/bump.php';
    } finally {
        foreach ($previous as $name => $value) {
            if ($value === null) {
                unset($_SERVER[$name], $_ENV[$name]);
                putenv($name);
            } else {
                $_SERVER[$name] = $_ENV[$name] = $value;
                putenv("{$name}={$value}");
            }
        }
    }
}

describe('API URL', function () {
    it('defaults to the production API host', function () {
        expect(configWithEnv(['BUMP_URL' => null])['url'])->toBe('https://api.bumpbunny.dev');
    });

    it('adds /v1 to the configured host', function (string $url) {
        config(['bump.url' => $url]);
        fakeApi();

        app(Delivery::class)->sendNow([['id' => 'a', 'trackable' => 'orders']]);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.bump.example/v1/events');
    })->with([
        'bare host' => 'https://api.bump.example',
        'trailing slash' => 'https://api.bump.example/',
        'ending in /v1' => 'https://api.bump.example/v1',
        'ending in /v1/' => 'https://api.bump.example/v1/',
    ]);

    it('notes a URL ending in /v1 once, at debug', function () {
        config(['bump.url' => 'https://api.bump.example/v1']);
        fakeApi();
        Log::spy();

        app(Delivery::class)->sendNow([['id' => 'a', 'trackable' => 'orders']]);
        app(Delivery::class)->sendNow([['id' => 'b', 'trackable' => 'orders']]);

        Log::shouldHaveReceived('debug')->once()->with("Bump's URL ends in /v1, which the package adds itself. Set BUMP_URL to https://api.bump.example.");
    });

    it('stays quiet about a bare host', function () {
        fakeApi();
        Log::spy();

        app(Delivery::class)->sendNow([['id' => 'a', 'trackable' => 'orders']]);

        Log::shouldNotHaveReceived('debug');
    });
});

describe('BUMP_ENABLED', function () {
    it('defaults to off under APP_ENV=testing and on elsewhere', function (?string $environment, bool $enabled) {
        expect(configWithEnv(['APP_ENV' => $environment, 'BUMP_ENABLED' => null])['enabled'])->toBe($enabled);
    })->with([
        'testing' => ['testing', false],
        'local' => ['local', true],
        'production' => ['production', true],
        'unset' => [null, true],
    ]);

    it('follows BUMP_ENABLED when it is set', function () {
        expect(configWithEnv(['APP_ENV' => 'testing', 'BUMP_ENABLED' => 'true'])['enabled'])->toBeTrue()
            ->and(configWithEnv(['APP_ENV' => 'production', 'BUMP_ENABLED' => 'false'])['enabled'])->toBeFalse();
    });
});

describe('a missing token', function () {
    it('warns once in production', function () {
        app()['env'] = 'production';
        config(['bump.live_token' => null]);
        Log::spy();

        Bump::track('orders');
        Bump::track('orders');

        Log::shouldHaveReceived('warning')->once()->with('Bump is off: BUMP_LIVE_TOKEN is not set.');
        Log::shouldNotHaveReceived('debug');
    });

    it('logs once at debug elsewhere', function () {
        app()['env'] = 'staging';
        config(['bump.test_token' => null]);
        Log::spy();

        Bump::track('orders');
        Bump::forget('usr_1');

        Log::shouldHaveReceived('debug')->once()->with('Bump is off: BUMP_TEST_TOKEN is not set.');
        Log::shouldNotHaveReceived('warning');
    });
});

it('names the package and its version in the User-Agent', function () {
    fakeApi();

    app(Delivery::class)->sendNow([['id' => 'a', 'trackable' => 'orders']]);

    $version = ltrim((string) InstalledVersions::getPrettyVersion('stickysoftware/bump-laravel'), 'v');

    Http::assertSent(fn (Request $request) => $request->header('User-Agent') === ["bump-laravel/{$version}"]);
});

it('resolves the facade to Tracker', function () {
    expect(Bump::getFacadeRoot())->toBeInstanceOf(Tracker::class)
        ->and(app(Tracker::class))->toBe(app(Tracker::class))
        ->and(class_exists('StickySoftware\Bump\Bump'))->toBeFalse();
});
