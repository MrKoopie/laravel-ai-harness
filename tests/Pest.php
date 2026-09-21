<?php

declare(strict_types=1);
use Symfony\Component\Process\Process;

function temp_file(string $prefix): string
{
    $path = tempnam(sys_get_temp_dir(), $prefix);

    if ($path === false) {
        throw new RuntimeException('Unable to create a temporary file.');
    }

    return $path;
}

function temp_directory(string $prefix): string
{
    $path = temp_file($prefix);

    if (! unlink($path) || ! mkdir($path, 0755, true)) {
        throw new RuntimeException('Unable to create a temporary directory.');
    }

    return $path;
}

function write_executable(string $path, string $contents): void
{
    $directory = dirname($path);

    if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
        throw new RuntimeException("Unable to create directory [{$directory}].");
    }

    if (file_put_contents($path, $contents) === false || ! chmod($path, 0755)) {
        throw new RuntimeException("Unable to write executable [{$path}].");
    }
}

function package_root(): string
{
    return dirname(__DIR__);
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>  $environment
 */
function harness_process(array $arguments, string $workingDirectory, array $environment = []): Process
{
    return new Process(
        [package_root().'/bin/ai-harness', ...$arguments],
        $workingDirectory,
        $environment === [] ? null : $environment,
    );
}
