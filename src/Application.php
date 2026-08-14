<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness;

use MrKoopie\LaravelAiHarness\Config\ConfigLoader;
use MrKoopie\LaravelAiHarness\Console\DoctorCommand;
use MrKoopie\LaravelAiHarness\Console\EnvironmentActionCommand;
use MrKoopie\LaravelAiHarness\Console\HookCommand;
use MrKoopie\LaravelAiHarness\Console\InitCommand;
use MrKoopie\LaravelAiHarness\Console\RuntimeCommand;
use MrKoopie\LaravelAiHarness\Environment\CommandFactory;
use MrKoopie\LaravelAiHarness\Environment\EnvironmentFile;
use MrKoopie\LaravelAiHarness\Environment\EnvironmentManager;
use MrKoopie\LaravelAiHarness\Environment\StateStore;
use MrKoopie\LaravelAiHarness\Files\ClaudeSettings;
use MrKoopie\LaravelAiHarness\Files\ProjectInstaller;
use MrKoopie\LaravelAiHarness\Files\SafeWriter;
use MrKoopie\LaravelAiHarness\Health\HealthChecker;
use MrKoopie\LaravelAiHarness\Process\ExecutableLocator;
use MrKoopie\LaravelAiHarness\Process\ProcessRunner;
use Symfony\Component\Console\Application as SymfonyApplication;

final class Application extends SymfonyApplication
{
    public function __construct()
    {
        parent::__construct('Laravel AI Harness', '1.0.0-dev');

        $config = new ConfigLoader;
        $writer = new SafeWriter;
        $executables = new ExecutableLocator;
        $processes = new ProcessRunner;
        $commands = new CommandFactory($executables);
        $environment = new EnvironmentManager(
            $config,
            $commands,
            $processes,
            new EnvironmentFile($writer),
            new StateStore($writer),
        );

        $this->addCommands([
            new InitCommand($config, new ProjectInstaller($writer, new ClaudeSettings($writer))),
            new DoctorCommand($config, new HealthChecker($executables)),
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
}
