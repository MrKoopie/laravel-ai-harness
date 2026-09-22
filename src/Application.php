<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness;

use Composer\InstalledVersions;
use MrKoopie\LaravelAiHarness\Config\ConfigLoader;
use MrKoopie\LaravelAiHarness\Console\CloudCommand;
use MrKoopie\LaravelAiHarness\Console\DoctorCommand;
use MrKoopie\LaravelAiHarness\Console\EnvironmentActionCommand;
use MrKoopie\LaravelAiHarness\Console\HookCommand;
use MrKoopie\LaravelAiHarness\Console\InitCommand;
use MrKoopie\LaravelAiHarness\Console\RuntimeCommand;
use MrKoopie\LaravelAiHarness\Console\UpdateCommand;
use MrKoopie\LaravelAiHarness\Environment\CloudManager;
use MrKoopie\LaravelAiHarness\Environment\CommandFactory;
use MrKoopie\LaravelAiHarness\Environment\EnvironmentFile;
use MrKoopie\LaravelAiHarness\Environment\EnvironmentManager;
use MrKoopie\LaravelAiHarness\Environment\StateStore;
use MrKoopie\LaravelAiHarness\Files\ClaudeSettings;
use MrKoopie\LaravelAiHarness\Files\ComposerScripts;
use MrKoopie\LaravelAiHarness\Files\ProjectInstaller;
use MrKoopie\LaravelAiHarness\Files\ProjectSynchronizer;
use MrKoopie\LaravelAiHarness\Files\SafeWriter;
use MrKoopie\LaravelAiHarness\Health\HealthChecker;
use MrKoopie\LaravelAiHarness\Process\ExecutableLocator;
use MrKoopie\LaravelAiHarness\Process\ProcessRunner;
use Symfony\Component\Console\Application as SymfonyApplication;

final class Application extends SymfonyApplication
{
    /** Register the commands and services exposed by the harness CLI. */
    public function __construct()
    {
        parent::__construct('Laravel AI Harness', self::packageVersion());

        $config = new ConfigLoader;
        $writer = new SafeWriter;
        $executables = new ExecutableLocator;
        $processes = new ProcessRunner;
        $commands = new CommandFactory($executables);
        $cloud = new CloudManager($config, $commands, $processes, new EnvironmentFile($writer), new StateStore($writer));
        $environment = new EnvironmentManager(
            $config,
            $commands,
            $processes,
            new EnvironmentFile($writer),
            new StateStore($writer),
            $cloud,
        );
        $synchronizer = new ProjectSynchronizer(
            $config,
            new ProjectInstaller($writer, new ClaudeSettings($writer)),
            new ComposerScripts($writer),
        );

        $this->addCommands([
            new CloudCommand($cloud),
            new InitCommand($synchronizer),
            new UpdateCommand($synchronizer),
            new DoctorCommand($config, new HealthChecker($executables, new ComposerScripts($writer))),
            new RuntimeCommand('artisan', 'artisan', $config, $commands, $processes),
            new RuntimeCommand('composer', 'composer', $config, $commands, $processes),
            new RuntimeCommand('php', 'php', $config, $commands, $processes),
            new RuntimeCommand('test', 'test', $config, $commands, $processes),
            new RuntimeCommand('npm', 'npm', $config, $commands, $processes),
            new EnvironmentActionCommand('up', 'up', $environment, 'Start configured Sail services'),
            new EnvironmentActionCommand('down', 'down', $environment, 'Stop configured Sail services without deleting volumes'),
            new EnvironmentActionCommand('setup', 'setup', $environment, 'Prepare the current checkout or worktree'),
            new EnvironmentActionCommand('cleanup', 'cleanup', $environment, 'Remove only resources owned by the harness'),
            new HookCommand($config, $environment),
        ]);
    }

    /** Resolve the installed package version with an honest development fallback. */
    private static function packageVersion(): string
    {
        if (! class_exists(InstalledVersions::class)) {
            return 'dev-main';
        }

        $version = InstalledVersions::getPrettyVersion('mrkoopie/laravel-ai-harness');

        return is_string($version) && $version !== '' ? $version : 'dev-main';
    }
}
