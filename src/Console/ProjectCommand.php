<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Console;

use MrKoopie\LaravelAiHarness\Support\ProjectPath;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

abstract class ProjectCommand extends Command
{
    protected function configureProjectPath(): void
    {
        $this->addOption('path', null, InputOption::VALUE_REQUIRED, 'Project root; defaults to the current directory');
    }

    protected function projectPath(InputInterface $input): string
    {
        $path = $input->getOption('path');

        return ProjectPath::resolve(is_string($path) ? $path : null);
    }
}
