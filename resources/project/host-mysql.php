<?php

declare(strict_types=1);
use MrKoopie\LaravelAiHarness\Environment\EnvironmentException;
use MrKoopie\LaravelAiHarness\Environment\HostMySql;

// Run with the selected site's PHP, using this harness installation's dependencies.
$autoloaders = [dirname(__DIR__, 2).'/vendor/autoload.php', dirname(__DIR__, 4).'/autoload.php'];
foreach ($autoloaders as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        break;
    }
}
try {
    (new HostMySql)->run($argv[2], $argv[1]);
} catch (Throwable $exception) {
    // PDO diagnostics may include credentials or connection details; keep CLI errors generic.
    fwrite(STDERR, $exception instanceof EnvironmentException
        ? $exception->getMessage()."\n" : "Host MySQL operation failed; verify the local service, credentials, and CREATE/DROP privileges. Ownership is retained for retry.\n");
    exit(1);
}
