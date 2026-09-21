<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Console;

use MrKoopie\LaravelAiHarness\Files\ProjectSynchronizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'update', description: 'Refresh package-managed project files after a package update')]
final class UpdateCommand extends ProjectCommand
{
    /** Create the project-file refresh command. */
    public function __construct(private readonly ProjectSynchronizer $synchronizer)
    {
        parent::__construct();
    }

    /** Configure the shared project path option. */
    protected function configure(): void
    {
        $this->configureProjectPath();
    }

    /** Refresh only package-managed project files. */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->synchronizer->sync($this->projectPath($input));

        if ($result->createdConfig) {
            $output->writeln('<info>created .ai-harness.config</info>');
        }

        foreach ($result->written as $path) {
            $output->writeln("<info>updated {$path}</info>");
        }

        $output->writeln('<info>AI Harness project files refreshed.</info>');

        return self::SUCCESS;
    }
}
