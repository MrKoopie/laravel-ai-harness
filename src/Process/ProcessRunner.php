<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Process;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

final class ProcessRunner
{
    /**
     * @param  non-empty-list<string>  $command
     * @param  array<string, string|false>  $environment
     */
    public function run(array $command, string $workingDirectory, OutputInterface $output, array $environment = [], mixed $input = null): int
    {
        $process = new Process($command, $workingDirectory, $environment === [] ? null : $environment);
        $process->setTimeout(null);

        if ($input !== null) {
            $process->setInput($input);
        }

        return $process->run(function (string $type, string $buffer) use ($output): void {
            $target = $output;

            if ($type === Process::ERR && $output instanceof ConsoleOutputInterface) {
                $target = $output->getErrorOutput();
            }

            $target->write($buffer, false, OutputInterface::OUTPUT_RAW);
        });
    }
}
