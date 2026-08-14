<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Console;

use MrKoopie\LaravelAiHarness\Config\ConfigLoader;
use MrKoopie\LaravelAiHarness\Environment\CommandFactory;
use MrKoopie\LaravelAiHarness\Process\ProcessRunner;
use MrKoopie\LaravelAiHarness\Support\ProjectPath;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RuntimeCommand extends Command
{
    public function __construct(
        string $name,
        private readonly string $tool,
        private readonly ConfigLoader $configLoader,
        private readonly CommandFactory $commands,
        private readonly ProcessRunner $processes,
    ) {
        parent::__construct($name);
        $this->setDescription("Run {$tool} through the configured project runtime");
    }

    protected function configure(): void
    {
        $this->addArgument('arguments', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Arguments forwarded unchanged to the runtime tool');
        $this->ignoreValidationErrors();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = ProjectPath::resolve();
        $config = $this->configLoader->load($root);
        $arguments = $input instanceof ArgvInput ? $input->getRawTokens(true) : $input->getArgument('arguments');

        if ($arguments !== [] && $arguments[0] === '--') {
            array_shift($arguments);
        }

        /** @var list<string> $arguments */
        $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;

        return $this->processes->run(
            $this->commands->runtime($config, $this->tool, $arguments, $root),
            $root,
            $output,
            input: is_resource($stream) ? $stream : STDIN,
            timeout: null,
        );
    }
}
