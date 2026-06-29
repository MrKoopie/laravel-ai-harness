<?php

declare(strict_types=1);

namespace Yourwebhoster\LaravelAiHarness\Commands;

use Yourwebhoster\LaravelAiHarness\Generation\ComposerScripts;
use Yourwebhoster\LaravelAiHarness\Generation\HarnessUpdater;

/**
 * Installs harness files and optional Composer lifecycle hooks.
 */
class InstallCommand extends HarnessCommand
{
    protected $signature = 'ai-harness:install
        {--path= : Project root to install into}
        {--with=* : Optional feature to include for this run, such as herd, docker, or polyscope}
        {--no-composer-hooks : Do not add Composer post-install/post-update hooks}';

    protected $description = 'Install Laravel AI Harness managed files and optional Composer update hooks.';

    /**
     * Write harness artifacts and patch Composer scripts when available.
     */
    public function handle(HarnessUpdater $updater): int
    {
        $path = $this->projectPathOption();

        $updater->update($path, $this->enabledFeatures());

        if (! $this->option('no-composer-hooks')) {
            $composerPath = rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'composer.json';

            if (file_exists($composerPath)) {
                (new ComposerScripts)->ensureAutoUpdateHooks($composerPath, $this->requestedFeatures());
                $this->line('updated composer.json scripts');
            }
        }

        $this->info('AI harness installed.');

        return self::SUCCESS;
    }
}
