<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Process;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

final class ProcessRunner
{
    private const LIFECYCLE_TIMEOUT = 300.0;

    /**
     * @param  non-empty-list<string>  $command
     * @param  array<string, string|false>  $environment
     */
    public function run(
        array $command,
        string $workingDirectory,
        OutputInterface $output,
        array $environment = [],
        mixed $input = null,
        ?float $timeout = self::LIFECYCLE_TIMEOUT,
    ): int {
        $process = new Process($command, $workingDirectory, $environment === [] ? null : $environment);
        $process->setTimeout($timeout);

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
