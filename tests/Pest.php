<?php

use Illuminate\Testing\PendingCommand;
use MrKoopie\LaravelAiHarness\Tests\TestCase;
use Symfony\Component\Process\Process;

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

/**
 * Run the generated `.dev/bin/ai-harness` wrapper inside a freshly provisioned
 * managed-worktree fixture that has Pest, paratest, and Pest's parallel plugin
 * registry present, then return the runtime command the fake `herd` shim logged.
 *
 * @param  array<int, string>  $arguments  Arguments passed after `.dev/bin/ai-harness`.
 * @param  array<string, string>  $environment  Extra environment variables for the run.
 */
function run_parallel_capable_wrapper(array $arguments, array $environment = []): string
{
    $path = temp_directory('ai-harness-wrapper');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    file_put_contents($path.'/.ai-harness.phpunit.xml', '<phpunit/>');

    $runtimeLog = temp_file('runtime-log');
    $fakeBin = $path.'/fake-bin';

    mkdir($path.'/vendor/bin', 0755, true);
    mkdir($fakeBin, 0755, true);

    file_put_contents($path.'/vendor/bin/pest', "#!/usr/bin/env bash\n");
    chmod($path.'/vendor/bin/pest', 0755);
    file_put_contents($path.'/vendor/bin/paratest', "#!/usr/bin/env bash\n");
    chmod($path.'/vendor/bin/paratest', 0755);
    file_put_contents($path.'/vendor/pest-plugins.json', '["Pest\\\\Plugins\\\\Parallel"]');

    file_put_contents($fakeBin.'/herd', <<<'BASH'
#!/usr/bin/env bash
printf 'herd %s\n' "$*" >> "$RUNTIME_LOG"
BASH);
    chmod($fakeBin.'/herd', 0755);

    $process = new Process(
        array_merge([$path.'/.dev/bin/ai-harness'], $arguments),
        $path,
        array_merge([
            'PATH' => $fakeBin.PATH_SEPARATOR.getenv('PATH'),
            'RUNTIME_LOG' => $runtimeLog,
        ], $environment),
    );

    $process->mustRun();

    return trim((string) file_get_contents($runtimeLog));
}
