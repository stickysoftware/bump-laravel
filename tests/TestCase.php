<?php

namespace StickySoftware\Bump\Tests;

use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;
use StickySoftware\Bump\Bump;
use StickySoftware\Bump\BumpServiceProvider;
use StickySoftware\Bump\Identifiables;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Bump::forgetReportedMissingToken();
        Identifiables::forgetReportedWarnings();
        Http::preventStrayRequests();
    }

    protected function getPackageProviders($app): array
    {
        return [BumpServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('bump.live_token', 'bump_live_secret');
        $app['config']->set('bump.test_token', 'bump_test_secret');
        $app['config']->set('bump.url', 'https://api.bump.example/v1');
    }
}
