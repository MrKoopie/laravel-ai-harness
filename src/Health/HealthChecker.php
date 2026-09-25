<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Health;

use MrKoopie\LaravelAiHarness\Config\Config;
use MrKoopie\LaravelAiHarness\Environment\ExecutionEnvironment;
use MrKoopie\LaravelAiHarness\Environment\Runtime;
use MrKoopie\LaravelAiHarness\Environment\Services;
use MrKoopie\LaravelAiHarness\Files\ComposerScripts;
use MrKoopie\LaravelAiHarness\Process\ExecutableLocator;

final readonly class HealthChecker
{
    /** Create a health checker backed by executable discovery. */
    public function __construct(
        private ExecutableLocator $executables,
        private ComposerScripts $composerScripts,
    ) {}

    /**
     * Check runtime tools and installed agent integration files.
     *
     * @return list<CheckResult>
     */
    public function check(Config $config, string $root): array
    {
        $checks = [
            $this->file(is_executable($root.'/.ai-harness'), '.ai-harness bootstrap is executable', '.ai-harness bootstrap is missing or not executable'),
            $this->file(is_file($root.'/artisan'), 'Laravel artisan entrypoint exists', 'Laravel artisan entrypoint is missing'),
            $this->file($config->sourceFiles !== [], 'Configuration loaded from '.implode(', ', $config->sourceFiles), 'No project configuration file exists; run init'),
            $this->file(
                $this->composerScripts->installed($root),
                'Automatic Composer refresh hooks are installed',
                'Automatic Composer refresh hooks are missing; run ./.ai-harness update',
            ),
        ];

        $cloud = ExecutionEnvironment::current()->isCloud();
        $checks[] = match ($cloud ? Runtime::Native : $config->runtime) {
            Runtime::Native => $this->file($this->executables->php() !== null, 'Native PHP is available', 'Native PHP is unavailable'),
            Runtime::Herd => $this->file($this->executables->herd() !== null, 'Laravel Herd is available', 'Laravel Herd is unavailable'),
            Runtime::Sail => $this->file(is_executable($root.'/vendor/bin/sail'), 'Laravel Sail is available', 'Laravel Sail is missing or not executable'),
        };

        if (! $cloud && $config->services === Services::Sail) {
            $checks[] = $this->file(is_executable($root.'/vendor/bin/sail'), 'Sail service manager is available', 'services=sail requires vendor/bin/sail');
        }

        if ($cloud) {
            foreach ($config->cloudServices as $service) {
                $binary = $service === 'redis' ? 'redis-cli' : 'mysql';
                $checks[] = $this->file($this->executables->find($binary) !== null, "Cloud {$binary} is available", "Cloud {$binary} is missing; run cloud provision");
            }
        }

        if ($config->supportsAgent('codex') || $config->supportsAgent('claude')) {
            $checks[] = $this->managed($root.'/AGENTS.md', 'Agent instructions');
        }

        if ($config->supportsAgent('codex') && $config->worktrees && ! $cloud) {
            $checks[] = $this->file(is_file($root.'/.codex/environments/environment.toml'), 'Codex local environment is installed', 'Codex local environment is missing');
        }

        if ($config->supportsAgent('claude')) {
            if ($config->worktrees || $config->cloud) {
                $settings = is_file($root.'/.claude/settings.json') ? file_get_contents($root.'/.claude/settings.json') : false;
                $checks[] = $this->file(
                    is_string($settings) && str_contains($settings, '.ai-harness\\" hook claude'),
                    'Claude hooks are installed',
                    'Claude hooks are missing',
                );
            }
        }

        return $checks;
    }

    /** Check whether a project file contains a harness-managed block. */
    private function managed(string $path, string $label): CheckResult
    {
        $contents = is_file($path) ? file_get_contents($path) : false;

        return $this->file(
            is_string($contents) && str_contains($contents, '<!-- ai-harness:start -->'),
            "{$label} are installed",
            "{$label} are missing",
        );
    }

    /** Create a health-check result with the appropriate message. */
    private function file(bool $passed, string $success, string $failure): CheckResult
    {
        return new CheckResult($passed, $passed ? $success : $failure);
    }
}
