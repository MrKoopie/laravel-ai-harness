<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Console;

use MrKoopie\LaravelAiHarness\Config\ConfigLoader;
use MrKoopie\LaravelAiHarness\Health\HealthChecker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'doctor', description: 'Validate configuration, runtime tools, and agent integration files')]
final class DoctorCommand extends ProjectCommand
{
    public function __construct(
        private readonly ConfigLoader $configLoader,
        private readonly HealthChecker $health,
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
        $config = $this->configLoader->load($root);
        $failed = false;

        $output->writeln(sprintf(
            'Runtime: <comment>%s</comment>; services: <comment>%s</comment>; agents: <comment>%s</comment>',
            $config->runtime->value,
            $config->services->value,
            $config->agents === [] ? 'none' : implode(', ', $config->agents),
        ));

        foreach ($this->health->check($config, $root) as $check) {
            $failed = $failed || ! $check->passed;
            $tag = $check->passed ? 'info' : 'error';
            $symbol = $check->passed ? 'OK' : 'FAIL';
            $output->writeln("<{$tag}>{$symbol}</{$tag}> {$check->message}");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
