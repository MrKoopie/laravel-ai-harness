<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Commands;

use MrKoopie\LaravelAiHarness\Drivers\DriverRegistry;
use MrKoopie\LaravelAiHarness\Generation\HarnessManifest;

/**
 * Reports missing generated artifacts and available drivers.
 */
class DoctorCommand extends HarnessCommand
{
    protected $signature = 'ai-harness:doctor
        {--path= : Project root to inspect}
        {--with=* : Optional feature to include for this run, such as herd, docker, or polyscope}';

    protected $description = 'Inspect Laravel AI Harness generated files and available drivers.';

    /**
     * Inspect selected manifest entries for the target project.
     */
    public function handle(DriverRegistry $drivers, HarnessManifest $manifest): int
    {
        $path = $this->projectPathOption();
        $missing = [];

        foreach ($manifest->entries($this->enabledFeatures()) as $entry) {
            if (! file_exists(rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$entry->path)) {
                $missing[] = $entry->path;
            }
        }

        $this->line('Agents: '.implode(', ', $drivers->agentNames()));
        $this->line('Runtimes: '.implode(', ', $drivers->runtimeNames()));

        if ($missing !== []) {
            foreach ($missing as $file) {
                $this->warn("missing {$file}");
            }

            return self::FAILURE;
        }

        $this->info('AI harness looks healthy.');

        return self::SUCCESS;
    }
}
