<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Console;

use MrKoopie\LaravelAiHarness\Environment\EnvironmentManager;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class EnvironmentActionCommand extends ProjectCommand
{
    /** Create a command that delegates to one environment action. */
    public function __construct(
        string $name,
        private readonly string $action,
        private readonly EnvironmentManager $environment,
        string $description,
    ) {
        parent::__construct($name);
        $this->setDescription($description);
    }

    /** Configure the shared project path option. */
    protected function configure(): void
    {
        $this->configureProjectPath();
    }

    /** Execute the configured environment action. */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = $this->projectPath($input);

        return match ($this->action) {
            'setup' => $this->environment->setup($root, $output),
            'cleanup' => $this->environment->cleanup($root, $output),
            'up' => $this->environment->up($root, $output),
            'down' => $this->environment->down($root, $output),
            default => throw new \LogicException("Unknown environment action [{$this->action}]."),
        };
    }
}
