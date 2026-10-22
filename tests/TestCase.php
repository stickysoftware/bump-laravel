<?php

namespace StickySoftware\Bump\Tests;

use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;
use StickySoftware\Bump\Api;
use StickySoftware\Bump\BumpServiceProvider;
use StickySoftware\Bump\Identifiables;
use StickySoftware\Bump\Tracker;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Tracker::forgetReportedMissingToken();
        Identifiables::forgetReportedWarnings();
        Api::forgetReportedWarnings();
        Http::preventStrayRequests();
    }

    protected function getPackageProviders($app): array
    {
        return [BumpServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // Queued jobs are encrypted, so the queue needs a key.
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('bump.live_token', 'bump_live_secret');
        $app['config']->set('bump.test_token', 'bump_test_secret');
        $app['config']->set('bump.url', 'https://api.bump.example');
        $app['config']->set('bump.enabled', true);
    }
}
