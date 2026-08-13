<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Health;

use MrKoopie\LaravelAiHarness\Config\Config;
use MrKoopie\LaravelAiHarness\Environment\Runtime;
use MrKoopie\LaravelAiHarness\Environment\Services;
use MrKoopie\LaravelAiHarness\Process\ExecutableLocator;

final readonly class HealthChecker
{
    public function __construct(private ExecutableLocator $executables) {}

    /** @return list<CheckResult> */
    public function check(Config $config, string $root): array
    {
        $checks = [
            $this->file(is_executable($root.'/.ai-harness'), '.ai-harness bootstrap is executable', '.ai-harness bootstrap is missing or not executable'),
            $this->file(is_file($root.'/artisan'), 'Laravel artisan entrypoint exists', 'Laravel artisan entrypoint is missing'),
            $this->file($config->sourceFiles !== [], 'Configuration loaded from '.implode(', ', $config->sourceFiles), 'No project configuration file exists; run init'),
        ];

        $checks[] = match ($config->runtime) {
            Runtime::Native => $this->file($this->executables->php() !== null, 'Native PHP is available', 'Native PHP is unavailable'),
            Runtime::Herd => $this->file($this->executables->herd() !== null, 'Laravel Herd is available', 'Laravel Herd is unavailable'),
            Runtime::Sail => $this->file(is_executable($root.'/vendor/bin/sail'), 'Laravel Sail is available', 'Laravel Sail is missing or not executable'),
        };

        if ($config->services === Services::Sail) {
            $checks[] = $this->file(is_executable($root.'/vendor/bin/sail'), 'Sail service manager is available', 'services=sail requires vendor/bin/sail');
        }

        if ($config->supportsAgent('codex')) {
            $checks[] = $this->managed($root.'/AGENTS.md', 'Codex instructions');

            if ($config->worktrees) {
                $checks[] = $this->file(is_file($root.'/.codex/environments/environment.toml'), 'Codex local environment is installed', 'Codex local environment is missing');
            }
        }

        if ($config->supportsAgent('claude')) {
            $checks[] = $this->managed($root.'/CLAUDE.md', 'Claude instructions');

            if ($config->worktrees) {
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

    private function managed(string $path, string $label): CheckResult
    {
        $contents = is_file($path) ? file_get_contents($path) : false;

        return $this->file(
            is_string($contents) && str_contains($contents, '<!-- ai-harness:start -->'),
            "{$label} are installed",
            "{$label} are missing",
        );
    }

    private function file(bool $passed, string $success, string $failure): CheckResult
    {
        return new CheckResult($passed, $passed ? $success : $failure);
    }
}
