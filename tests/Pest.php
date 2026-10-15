<?php

declare(strict_types=1);
use Symfony\Component\Process\Process;

// Keep every fixture independent of the environment used to launch the package tests.
$harnessSavedEnvironment = [];

uses()->beforeEach(function () use (&$harnessSavedEnvironment): void {
    $harnessSavedEnvironment = [];
    foreach (['AI_HARNESS_RUNTIME', 'AI_HARNESS_PHP_VERSION', 'AI_HARNESS_SITE_SECURE', 'AI_HARNESS_SERVICE_MANAGER', 'AI_HARNESS_ENV'] as $name) {
        $harnessSavedEnvironment[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }
})->afterEach(function () use (&$harnessSavedEnvironment): void {
    foreach ($harnessSavedEnvironment as $name => [$value, $env, $server]) {
        putenv($value === false ? $name : $name.'='.$value);
        unset($_ENV[$name], $_SERVER[$name]);
        if ($env !== null) {
            $_ENV[$name] = $env;
        }
        if ($server !== null) {
            $_SERVER[$name] = $server;
        }
    }
})->in('Unit', 'Feature');

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
 * @param  array<string, string|false>  $environment
 */
function harness_process(array $arguments, string $workingDirectory, array $environment = []): Process
{
    return new Process(
        [package_root().'/bin/ai-harness', ...$arguments],
        $workingDirectory,
        $environment === [] ? null : $environment,
    );
}
