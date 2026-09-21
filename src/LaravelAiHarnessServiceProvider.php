<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness;

use Illuminate\Support\ServiceProvider;
use MrKoopie\LaravelAiHarness\Console\LegacyUpdateCommand;

/**
 * One-release compatibility provider for v0.1 Composer hooks and cached manifests.
 */
class LaravelAiHarnessServiceProvider extends ServiceProvider
{
    /** Register the legacy Artisan update entrypoint when Laravel runs in the console. */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([LegacyUpdateCommand::class]);
        }
    }
}
