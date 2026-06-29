<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Commands;

use MrKoopie\LaravelAiHarness\Generation\HarnessUpdater;

/**
 * Refreshes package-managed harness artifacts.
 */
class UpdateCommand extends HarnessCommand
{
    protected $signature = 'ai-harness:update
        {--path= : Project root to update}
        {--with=* : Optional feature to include for this run, such as herd, docker, or polyscope}';

    protected $description = 'Refresh Laravel AI Harness managed files.';

    /**
     * Refresh selected manifest entries for the target project.
     */
    public function handle(HarnessUpdater $updater): int
    {
        $path = $this->projectPathOption();
        $written = $updater->update($path, $this->enabledFeatures());

        foreach ($written as $file) {
            $this->line("updated {$file}");
        }

        $this->info('AI harness files updated.');

        return self::SUCCESS;
    }
}
