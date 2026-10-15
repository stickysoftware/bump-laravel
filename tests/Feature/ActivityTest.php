<?php

// A web request leaves activity to the trackable's setting; jobs and commands send false; a call can override both.

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use StickySoftware\Bump\Facades\Bump;
use StickySoftware\Bump\Tests\Fixtures\TrackingJob;

beforeEach(function () {
    config(['bump.delivery' => 'sync']);
    fakeApi();
});

it('sends no activity from a web request', function () {
    inWebRequest();
    Route::post('/orders', fn () => Bump::track('orders'));

    $this->post('/orders');

    expect(sentEvents()[0])->not->toHaveKey('activity');
});

it('lets a web request pass activity', function () {
    inWebRequest();
    Route::post('/orders', fn () => Bump::track('orders', activity: false));

    $this->post('/orders');

    expect(sentEvents()[0]['activity'])->toBeFalse();
});

it('sends activity false from a console command', function () {
    Artisan::command('orders:import', fn () => Bump::track('orders'));

    $this->artisan('orders:import');
    app()->terminate();

    expect(sentEvents()[0]['activity'])->toBeFalse();
});

it('lets a console command pass activity', function () {
    Artisan::command('orders:import', fn () => Bump::track('orders', activity: true));

    $this->artisan('orders:import');
    app()->terminate();

    expect(sentEvents()[0]['activity'])->toBeTrue();
});

it('sends activity false from a queued job on a worker', function () {
    $this->loadLaravelMigrations();
    config(['queue.default' => 'database']);

    dispatch(new TrackingJob);
    $this->artisan('queue:work', ['--once' => true]);

    expect(sentEvents()[0]['activity'])->toBeFalse();
});

it('sends activity false from a job the sync queue runs inside a web request', function () {
    inWebRequest();
    Route::post('/orders', function () {
        Bump::track('orders');
        dispatch(new TrackingJob);
        Bump::track('orders');
    });

    $this->post('/orders');

    expect(sentEvents())->toHaveCount(3)
        ->and(sentEvents()[0])->not->toHaveKey('activity')
        ->and(sentEvents()[1]['activity'])->toBeFalse()
        ->and(sentEvents()[2])->not->toHaveKey('activity');
});

it('lets a job pass activity', function () {
    dispatch(new TrackingJob(activity: true));

    expect(sentEvents()[0]['activity'])->toBeTrue();
});

it('keeps adjust out of activity in a web request too', function () {
    inWebRequest();
    Route::post('/refunds', fn () => Bump::adjust('orders', amount: -10));

    $this->post('/refunds');

    expect(sentEvents()[0]['activity'])->toBeFalse();
});
