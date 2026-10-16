<?php

namespace StickySoftware\Bump;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use StickySoftware\Bump\Console\BackfillCommand;

class BumpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/bump.php', 'bump');

        $this->app->singleton(Bump::class);
        $this->app->singleton(Context::class);
        $this->app->scoped(Buffer::class);
    }

    public function boot(Dispatcher $events): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/bump.php' => config_path('bump.php')], 'bump-config');

            $this->commands([BackfillCommand::class]);

            AboutCommand::add('Bump', fn (Api $api) => [
                'Enabled' => AboutCommand::format((bool) config('bump.enabled'), console: fn (bool $enabled) => $enabled ? 'ENABLED' : 'OFF'),
                'Environment token' => $api->token() === null
                    ? '<fg=yellow;options=bold>'.$api->tokenVariable().' missing</>'
                    : $api->tokenVariable().' set',
                'Delivery' => config('bump.delivery'),
                'Handles' => config('bump.handles'),
                'API URL' => config('bump.url'),
            ]);
        }

        // Resolved through app() at call time, not $this->app: Octane runs each request in a clone.
        $this->app->terminating(fn () => app(Bump::class)->flush());

        $events->listen(JobProcessing::class, fn () => app(Context::class)->jobStarted());
        $events->listen([JobProcessed::class, JobFailed::class], fn () => app(Bump::class)->flush());
        $events->listen(JobAttempted::class, function () {
            app(Context::class)->jobEnded();

            $this->dropEventsOfRetriedAttempt();
        });
    }

    /**
     * An attempt that threw and goes back on the queue fires neither JobProcessed nor JobFailed. Its
     * events stay unsent: the next attempt records them again, and sending both would count twice.
     */
    private function dropEventsOfRetriedAttempt(): void
    {
        $dropped = count(app(Buffer::class)->pull());

        if ($dropped > 0) {
            Log::warning("Bump dropped {$dropped} events from a job attempt that failed and will run again.");
        }
    }
}
