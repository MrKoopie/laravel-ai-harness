<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Commands;

use Illuminate\Console\Command;

/**
 * Base command helpers shared by all harness Artisan commands.
 */
abstract class HarnessCommand extends Command
{
    /**
     * Merge enabled config features with sanitized one-off CLI feature flags.
     *
     * @return list<string>
     */
    protected function enabledFeatures(): array
    {
        $features = [];
        $configuredFeatures = config('ai-harness.features', []);

        if (is_array($configuredFeatures)) {
            foreach ($configuredFeatures as $feature => $enabled) {
                if (is_string($feature) && $enabled === true) {
                    $features[] = $feature;
                }
            }
        }

        return array_values(array_unique(array_merge($features, $this->requestedFeatures())));
    }

    /**
     * Resolve the target project path for command execution.
     */
    protected function projectPathOption(): string
    {
        $path = $this->option('path');

        if (is_string($path) && $path !== '') {
            return $path;
        }

        return base_path();
    }

    /**
     * Return normalized `--with` feature values from the command line.
     *
     * @return list<string>
     */
    protected function requestedFeatures(): array
    {
        $features = $this->option('with');

        if (is_string($features)) {
            $features = [$features];
        }

        if (! is_array($features)) {
            return [];
        }

        $normalized = array_map(
            static fn (mixed $feature): ?string => is_string($feature) ? trim($feature) : null,
            $features,
        );

        return array_values(array_unique(array_filter(
            $normalized,
            static fn (?string $feature): bool => $feature !== null && $feature !== '',
        )));
    }
}
