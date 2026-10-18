<?php

// php artisan about shows a Bump section with the settings that decide whether and how events go out, never the token.

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;

function about(): array
{
    Artisan::call('about', ['--only' => 'bump', '--json' => true]);

    return json_decode(Artisan::output(), true)['bump'];
}

function decoratedAbout(): string
{
    $output = new BufferedOutput(decorated: true);
    Artisan::call('about', ['--only' => 'bump'], $output);

    return $output->fetch();
}

it('shows the settings and that the token is set', function () {
    expect(about())->toBe([
        'enabled' => true,
        'environment_token' => 'BUMP_TEST_TOKEN set',
        'delivery' => 'queue',
        'handles' => 'prefixed_id',
        'api_url' => 'https://api.bump.example',
    ]);
});

it('names the live token in production', function () {
    app()['env'] = 'production';

    expect(about()['environment_token'])->toBe('BUMP_LIVE_TOKEN set');
});

it('shows a missing token in yellow', function () {
    config(['bump.test_token' => null]);

    expect(about()['environment_token'])->toBe('BUMP_TEST_TOKEN missing')
        ->and(decoratedAbout())->toContain("\e[33;1mBUMP_TEST_TOKEN missing\e[39;22m");
});

it('shows a set token without color', function () {
    expect(decoratedAbout())->toContain(' BUMP_TEST_TOKEN set')
        ->not->toContain("\e[33;1mBUMP_TEST_TOKEN");
});

it('never shows the token itself', function () {
    $this->artisan('about')
        ->doesntExpectOutputToContain('bump_test_secret')
        ->doesntExpectOutputToContain('bump_live_secret')
        ->assertSuccessful();

    expect(decoratedAbout())->not->toContain('bump_test_secret');
});

it('shows ENABLED or OFF', function () {
    $this->artisan('about', ['--only' => 'bump'])->expectsOutputToContain('ENABLED');

    config(['bump.enabled' => false]);

    $this->artisan('about', ['--only' => 'bump'])->expectsOutputToContain('OFF');
    expect(about()['enabled'])->toBeFalse();
});
