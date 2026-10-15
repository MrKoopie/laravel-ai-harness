<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Console;

use MrKoopie\LaravelAiHarness\Files\ProjectSynchronizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'init', description: 'Install or refresh the small project-side harness files')]
final class InitCommand extends ProjectCommand
{
    /** Create the project integration installer command. */
    public function __construct(private readonly ProjectSynchronizer $synchronizer)
    {
        parent::__construct();
    }

    /** Configure the shared project path option. */
    protected function configure(): void
    {
        $this->configureProjectPath();
        $this->addOption('global', null, InputOption::VALUE_NONE, 'Use the global Composer command and a Git-ignored personal configuration');
    }

    /** Install or refresh the project-side harness files. */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->synchronizer->sync($this->projectPath($input), (bool) $input->getOption('global'));

        if ($result->createdConfig) {
            $output->writeln("<info>created {$result->configFilename}</info>");
        }

        foreach ($result->written as $path) {
            $output->writeln("<info>updated {$path}</info>");
        }

        $output->writeln('<info>AI Harness initialized.</info>');

        return self::SUCCESS;
    }
}
