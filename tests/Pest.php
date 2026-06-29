<?php

use Illuminate\Testing\PendingCommand;
use MrKoopie\LaravelAiHarness\Tests\TestCase;

use function Pest\Laravel\artisan;

pest()->extend(TestCase::class)->in('Feature');

function temp_file(string $prefix): string
{
    $path = tempnam(sys_get_temp_dir(), $prefix);

    if ($path === false) {
        throw new RuntimeException('Unable to create temp file.');
    }

    return $path;
}

function temp_directory(string $prefix): string
{
    $path = temp_file($prefix);

    if (! unlink($path)) {
        throw new RuntimeException('Unable to remove temp file.');
    }

    if (! mkdir($path, 0755, true)) {
        throw new RuntimeException('Unable to create temp directory.');
    }

    return $path;
}

/**
 * @param  array<string, mixed>  $parameters
 */
function pending_artisan(string $command, array $parameters = []): PendingCommand
{
    $pendingCommand = artisan($command, $parameters);

    if (! $pendingCommand instanceof PendingCommand) {
        throw new RuntimeException('Expected a pending Artisan command.');
    }

    return $pendingCommand;
}
