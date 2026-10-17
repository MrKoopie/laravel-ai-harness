<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Console;

use MrKoopie\LaravelAiHarness\Environment\EnvironmentManager;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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
        if ($this->action === 'setup') {
            $this->addOption('migrate', null, InputOption::VALUE_NONE, 'Run development migrations after setup');
            $this->addOption('build', null, InputOption::VALUE_NONE, 'Install npm dependencies and build after setup');
        }
    }

    /** Execute the configured environment action. */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = $this->projectPath($input);

        if ($this->action === 'setup') {
            $status = $this->environment->setup($root, $output);

            return $status === 0 ? $this->environment->finishSetup($root, $output, (bool) $input->getOption('migrate'), (bool) $input->getOption('build')) : $status;
        }

        return match ($this->action) {
            'cleanup' => $this->environment->cleanup($root, $output),
            'up' => $this->environment->up($root, $output),
            'down' => $this->environment->down($root, $output),
            default => throw new \LogicException("Unknown environment action [{$this->action}]."),
        };
    }
}
