<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness;

use MrKoopie\LaravelAiHarness\Commands\DoctorCommand;
use MrKoopie\LaravelAiHarness\Commands\InstallCommand;
use MrKoopie\LaravelAiHarness\Commands\UpdateCommand;
use MrKoopie\LaravelAiHarness\Drivers\DriverRegistry;
use MrKoopie\LaravelAiHarness\Generation\HarnessManifest;
use MrKoopie\LaravelAiHarness\Generation\HarnessUpdater;
use MrKoopie\LaravelAiHarness\Generation\ManagedBlockWriter;
use MrKoopie\LaravelAiHarness\Generation\TemplateRenderer;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * Registers commands and services for Laravel AI Harness.
 */
class LaravelAiHarnessServiceProvider extends PackageServiceProvider
{
    /**
     * Configure package metadata, config publishing, and commands.
     */
    public function configurePackage(Package $package): void
    {
        $package
            ->name('ai-harness')
            ->hasConfigFile()
            ->hasCommands([
                InstallCommand::class,
                UpdateCommand::class,
                DoctorCommand::class,
            ]);
    }

    /**
     * Register the package's runtime services.
     */
    public function packageRegistered(): void
    {
        $this->app->singleton(DriverRegistry::class, fn (): DriverRegistry => DriverRegistry::defaults());
        $this->app->singleton(ManagedBlockWriter::class, fn (): ManagedBlockWriter => new ManagedBlockWriter($this->managedBlockMarker()));
        $this->app->singleton(TemplateRenderer::class);
        $this->app->singleton(HarnessManifest::class);
        $this->app->singleton(HarnessUpdater::class);
    }

    /**
     * @return non-empty-string
     */
    private function managedBlockMarker(): string
    {
        $marker = config('ai-harness.package.marker', 'ai-harness');

        if (is_string($marker)) {
            $marker = trim($marker);

            if ($marker !== '') {
                return $marker;
            }
        }

        return 'ai-harness';
    }
}
