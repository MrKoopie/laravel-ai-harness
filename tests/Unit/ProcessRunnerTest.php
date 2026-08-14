<?php

declare(strict_types=1);

use MrKoopie\LaravelAiHarness\Process\ProcessRunner;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

test('lifecycle commands have a finite timeout', function (): void {
    $runner = new ProcessRunner;

    expect(fn (): int => $runner->run(
        [PHP_BINARY, '-r', 'usleep(500000);'],
        temp_directory('harness-process-timeout'),
        new BufferedOutput,
        timeout: 0.01,
    ))->toThrow(ProcessTimedOutException::class);
});

test('runtime commands can intentionally run without a timeout', function (): void {
    $runner = new ProcessRunner;

    expect($runner->run(
        [PHP_BINARY, '-r', 'usleep(100000); exit(17);'],
        temp_directory('harness-process-unbounded'),
        new BufferedOutput,
        timeout: null,
    ))->toBe(17);
});
