<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Health;

use MrKoopie\LaravelAiHarness\Config\Config;
use MrKoopie\LaravelAiHarness\Environment\ComposerAuth;
use MrKoopie\LaravelAiHarness\Environment\EnvironmentException;
use MrKoopie\LaravelAiHarness\Environment\EnvironmentFile;
use MrKoopie\LaravelAiHarness\Environment\ExecutionEnvironment;
use MrKoopie\LaravelAiHarness\Environment\ExistingMySql;
use MrKoopie\LaravelAiHarness\Environment\Runtime;
use MrKoopie\LaravelAiHarness\Environment\Services;
use MrKoopie\LaravelAiHarness\Environment\StateStore;
use MrKoopie\LaravelAiHarness\Environment\Yerd;
use MrKoopie\LaravelAiHarness\Files\ComposerScripts;
use MrKoopie\LaravelAiHarness\Files\SafeWriter;
use MrKoopie\LaravelAiHarness\Process\ExecutableLocator;
use MrKoopie\LaravelAiHarness\Support\GlobalInstallation;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

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
        $global = GlobalInstallation::active($root);
        $environmentSources = array_filter($config->sources, static fn (string $source): bool => str_starts_with($source, 'AI_HARNESS_'));
        $configurationSources = array_unique([...$config->sourceFiles, ...array_values($environmentSources)]);
        $checks = [
            $this->file(is_file($root.'/artisan'), 'Laravel artisan entrypoint exists', 'Laravel artisan entrypoint is missing'),
            $this->file($configurationSources !== [], 'Configuration loaded from '.implode(', ', $configurationSources), 'No project configuration file exists; run init'),
            $global ? $this->file(
                $this->executables->find('ai-harness') !== null,
                'Global AI Harness is available on PATH; refresh with ai-harness update',
                'Global AI Harness is missing from PATH; add the Composer global bin directory',
            ) : $this->file(
                $this->composerScripts->installed($root),
                'Automatic Composer refresh hooks are installed',
                'Automatic Composer refresh hooks are missing; run ./.ai-harness update',
            ),
        ];

        foreach (['runtime' => $config->runtime->value, 'php_version' => $config->phpVersion ?? '(empty)', 'site_secure' => $config->siteSecure ? 'true' : 'false', 'service_manager' => $config->serviceManager->value] as $key => $value) {
            $checks[] = new CheckResult(true, "{$key}={$value} from ".($config->sources[$key] ?? 'built-in default'));
        }

        if (! $global) {
            array_unshift($checks, $this->file(is_executable($root.'/.ai-harness'), '.ai-harness bootstrap is executable', '.ai-harness bootstrap is missing or not executable'));
        }

        $cloud = ExecutionEnvironment::current()->isCloud();

        if (! $cloud && ($config->localEnvironment !== [] || $config->managesMySql())) {
            foreach ($config->localEnvironmentSources as $name => $source) {
                // All values are withheld, including application-specific secret names.
                $checks[] = new CheckResult(true, "Local override {$name} from {$source} (value redacted)");
            }

            $environmentFile = new EnvironmentFile(new SafeWriter);

            foreach ($environmentFile->localOverrideConflicts($root, $config->localEnvironment, $config->managesMySql(), $config->runtime === Runtime::Sail, $config->existingDatabase !== null) as $name) {
                $checks[] = new CheckResult(false, "Local environment override conflicts with {$name}; remove it or explicitly clear the URL/socket override");
            }

            if ($config->localEnvironment !== []) {
                $checks[] = $this->file(
                    ! file_exists($root.'/bootstrap/cache/config.php'),
                    'No cached Laravel configuration overrides local environment settings',
                    'Laravel configuration is cached; setup clears it when applying local overrides',
                );
            }
        }

        $mode = $cloud ? 'cloud' : ($config->existingDatabase !== null ? 'existing-server MySQL/MariaDB' : ($config->managesMySql() ? 'Sail MySQL' : 'application-managed / SQLite defaults'));
        $checks[] = new CheckResult(true, 'Database provisioning mode: '.$mode);
        if (! $cloud && $config->existingDatabase !== null) {
            foreach ($config->existingDatabase->environmentOverrides as $variable) {
                $checks[] = new CheckResult(true, "Database override {$variable} (value redacted)");
            }
            $environment = new EnvironmentFile(new SafeWriter);
            foreach ($environment->existingEnvironmentConflicts($root, $config->existingDatabase) as $name) {
                $checks[] = new CheckResult(false, 'Existing-server database configuration conflict: '.$name);
            }
            [$passed, $message] = (new ExistingMySql(new StateStore(new SafeWriter)))->diagnose($config->existingDatabase);
            $checks[] = new CheckResult($passed, $message);
        }

        $checks[] = match ($cloud ? Runtime::Native : $config->runtime) {
            Runtime::Native => $this->file($this->executables->php() !== null, 'Native PHP is available', 'Native PHP is unavailable'),
            Runtime::Herd => $this->file($this->executables->herd() !== null, 'Laravel Herd is available', 'Laravel Herd is unavailable'),
            Runtime::Yerd => $this->yerd($config, $root),
            Runtime::Valet => $this->file($this->executables->valet() !== null, 'Laravel Valet is available', 'Laravel Valet is unavailable'),
            Runtime::Sail => $this->file(is_executable($root.'/vendor/bin/sail'), 'Laravel Sail is available', 'Laravel Sail is missing or not executable'),
        };

        if (! $cloud && $config->serviceManager === Services::Sail) {
            $checks[] = $this->file(is_executable($root.'/vendor/bin/sail'), 'Sail service manager is available', 'service_manager=sail requires vendor/bin/sail');
        }

        $composerAuth = ComposerAuth::fromEnvironment();

        if ($composerAuth !== null) {
            $checks[] = $this->file(
                $composerAuth->valid(),
                'COMPOSER_AUTH is valid for '.$composerAuth->summary(),
                'COMPOSER_AUTH is not valid: '.implode('; ', $composerAuth->errors),
            );

            // Sail runs Composer in a container, which gets only the variables that the compose file forwards.
            if (! $cloud && $config->runtime === Runtime::Sail) {
                $forwarding = ComposerAuth::composeFileForwarding($root);
                $checks[] = $this->file(
                    $forwarding !== null,
                    'COMPOSER_AUTH is forwarded to Sail in '.basename((string) $forwarding),
                    'COMPOSER_AUTH is set but the Sail compose file does not forward it; add COMPOSER_AUTH to the '.ComposerAuth::sailService($root).' environment, then run ./.ai-harness up',
                );
            }
        }

        if ($cloud) {
            foreach ($config->cloudServices as $service) {
                $binary = $service === 'redis' ? 'redis-cli' : 'mysql';
                $checks[] = $this->file($this->executables->find($binary) !== null, "Cloud {$binary} is available", "Cloud {$binary} is missing; run cloud provision");
            }
        }

        if ($config->supportsAgent('codex') || $config->supportsAgent('claude')) {
            if (! $global || $config->supportsAgent('codex')) {
                $checks[] = $this->managed($root.($global ? '/AGENTS.override.md' : '/AGENTS.md'), 'Agent instructions');
            }
        }

        if ($config->supportsAgent('codex') && $config->worktrees && ! $cloud) {
            $checks[] = $this->file(is_file($root.'/'.($global ? GlobalInstallation::CODEX_ENVIRONMENT : '.codex/environments/environment.toml')), 'Codex local environment is installed', 'Codex local environment is missing');
        }

        if ($config->supportsAgent('claude')) {
            if ($global) {
                $checks[] = $this->managed($root.'/CLAUDE.local.md', 'Claude local instructions');
            }

            if (! $global && is_file($root.'/CLAUDE.md')) {
                $claudeInstructions = is_link($root.'/CLAUDE.md') ? false : file_get_contents($root.'/CLAUDE.md');
                $checks[] = $this->file(
                    is_string($claudeInstructions) && $this->importsAgents($claudeInstructions),
                    'Claude instructions in CLAUDE.md import AGENTS.md',
                    'Claude instructions in CLAUDE.md shadow AGENTS.md; add @AGENTS.md or configure Claude to read both',
                );
            }

            if ($config->worktrees || $config->cloud) {
                $settingsPath = $root.($global ? '/.claude/settings.local.json' : '/.claude/settings.json');
                $settings = is_file($settingsPath) ? file_get_contents($settingsPath) : false;
                $checks[] = $this->file(
                    is_string($settings) && str_contains($settings, $global ? 'ai-harness hook claude' : '.ai-harness\\" hook claude'),
                    'Claude hooks are installed',
                    'Claude hooks are missing',
                );
            }
        }

        return $checks;
    }

    /** Check the daemon and the selected PHP and Composer shims. */
    private function yerd(Config $config, string $root): CheckResult
    {
        try {
            $binary = $this->executables->yerd();
            if ($binary === null) {
                throw new EnvironmentException('Yerd executable is unavailable.');
            }
            $yerd = new Yerd;
            $yerd->url($binary, $root, $config->siteSecure);
            $php = $yerd->php($binary, $root, $config->phpVersion);
            $probe = new Process([$php, '--version'], $root);
            $probe->setTimeout(10);
            $probe->run();
            if (! $probe->isSuccessful()) {
                throw new EnvironmentException('Yerd PHP shim could not execute; check that the selected PHP version is installed.');
            }
            $yerd->composer($binary, $this->executables->composer());

            return new CheckResult(true, 'Yerd daemon, PHP shim, and Composer are available');
        } catch (EnvironmentException|ExceptionInterface $exception) {
            return new CheckResult(false, $exception->getMessage());
        }
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

    /** Find a Claude import outside Markdown comments and code spans. */
    private function importsAgents(string $contents): bool
    {
        $contents = preg_replace('/<!--.*?-->/s', '', $contents);

        if ($contents === null) {
            return false;
        }

        $fence = null;

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if (preg_match('/^[ \t]*(`{3,}|~{3,})/', $line, $matches) === 1) {
                $marker = $matches[1][0];
                $length = strlen($matches[1]);

                if ($fence === null) {
                    $fence = [$marker, $length];
                } elseif ($marker === $fence[0] && $length >= $fence[1]) {
                    $fence = null;
                }

                continue;
            }

            if ($fence !== null) {
                continue;
            }

            $line = preg_replace('/(`+).*?\1/', '', $line);

            if (is_string($line) && str_contains($line, '@AGENTS.md')) {
                return true;
            }
        }

        return false;
    }

    /** Create a health-check result with the appropriate message. */
    private function file(bool $passed, string $success, string $failure): CheckResult
    {
        return new CheckResult($passed, $passed ? $success : $failure);
    }
}
