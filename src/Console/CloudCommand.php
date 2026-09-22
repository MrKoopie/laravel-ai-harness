<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Console;

use MrKoopie\LaravelAiHarness\Environment\CloudManager;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class CloudCommand extends ProjectCommand
{
    /** Register the cloud lifecycle entrypoint. */
    public function __construct(private readonly CloudManager $cloud)
    {
        parent::__construct('cloud');
        $this->setDescription('Prepare or clean a Claude/Codex cloud checkout');
    }

    /** Configure a lifecycle action and explicit project path. */
    protected function configure(): void
    {
        $this->configureProjectPath();
        $this->addArgument('action', InputArgument::REQUIRED, 'setup, maintain, or cleanup');
    }

    /** Execute the selected cloud action. */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return match ($input->getArgument('action')) {
            'setup', 'maintain' => $this->cloud->setup($this->projectPath($input), $output),
            'cleanup' => $this->cloud->cleanup($this->projectPath($input), $output),
            default => throw new \RuntimeException('Cloud action must be setup, maintain, or cleanup.'),
        };
    }
}
