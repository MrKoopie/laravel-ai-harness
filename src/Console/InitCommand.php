<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Console;

use MrKoopie\LaravelAiHarness\Config\ConfigLoader;
use MrKoopie\LaravelAiHarness\Files\ProjectInstaller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'init', description: 'Install or refresh the small project-side harness files')]
final class InitCommand extends ProjectCommand
{
    public function __construct(
        private readonly ConfigLoader $configLoader,
        private readonly ProjectInstaller $installer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->configureProjectPath();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = $this->projectPath($input);

        if ($this->installer->ensureConfig($root)) {
            $output->writeln('<info>created .ai-harness.config</info>');
        }

        $config = $this->configLoader->load($root);

        foreach ($this->installer->install($root, $config) as $path) {
            $output->writeln("<info>updated {$path}</info>");
        }

        $output->writeln('<info>AI Harness initialized.</info>');

        return self::SUCCESS;
    }
}
