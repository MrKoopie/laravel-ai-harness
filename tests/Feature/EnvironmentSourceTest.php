<?php

declare(strict_types=1);

use MrKoopie\LaravelAiHarness\Config\ConfigLoader;
use MrKoopie\LaravelAiHarness\Environment\DatabaseName;

/** @return array{string, string} */
function environment_source_fixture(): array
{
    $primary = temp_directory('harness-env-primary');
    $root = temp_directory('harness-env-worktree');
    $metadata = $primary.'/.git/worktrees/fixture';
    mkdir($metadata, 0755, true);
    file_put_contents($metadata.'/commondir', '../..');
    file_put_contents($metadata.'/gitdir', $root.'/.git');
    file_put_contents($root.'/.git', 'gitdir: '.$metadata);
    mkdir($root.'/vendor');
    file_put_contents($root.'/vendor/autoload.php', '<?php');
    file_put_contents($root.'/.env.example', "APP_KEY=example-key\nTEST_API_KEY=\n");

    return [$primary, $root];
}

test('primary environment copying is opt in and never replaces existing environment files', function (): void {
    [$primary, $root] = environment_source_fixture();
    file_put_contents($primary.'/.env', "APP_KEY=original-key\nTEST_API_KEY=synthetic-token\n");

    harness_process(['setup'], $root)->mustRun();
    expect(file_get_contents($root.'/.env'))->toBe(file_get_contents($root.'/.env.example'));

    file_put_contents($primary.'/.ai-harness.config.local', 'env_source=primary');
    harness_process(['setup'], $root)->mustRun();
    expect(file_get_contents($root.'/.env'))->toBe(file_get_contents($root.'/.env.example'));

    unlink($root.'/.env');
    file_put_contents($root.'/artisan', '<?php');
    $process = harness_process(['setup'], $root)->mustRun();
    $testing = file_get_contents($root.'/.env.testing');
    expect(file_get_contents($root.'/.env'))->toBe(file_get_contents($primary.'/.env'))
        ->and($process->getOutput())->toContain('primary checkout .env')
        ->and($process->getOutput())->not->toContain('synthetic-token')
        ->and($testing)->toContain('APP_KEY=original-key', 'DB_DATABASE=:memory:');

    file_put_contents($primary.'/.env', "APP_KEY=changed-key\nTEST_API_KEY=changed-token\n");
    harness_process(['setup'], $root)->mustRun();
    expect(file_get_contents($root.'/.env'))->toContain('APP_KEY=original-key', 'TEST_API_KEY=synthetic-token')
        ->and(file_get_contents($root.'/.env.testing'))->toBe($testing);
});

test('copied primary environments receive overrides and checkout specific managed values', function (): void {
    [$primary, $root] = environment_source_fixture();
    $original = "APP_KEY=original-key\nTEST_API_KEY=synthetic-token\nFORWARD_DB_PORT=3317\nDB_DATABASE=primary\nAPP_URL=http://primary.test\n";
    file_put_contents($primary.'/.env', $original);
    file_put_contents($primary.'/.ai-harness.config.local', "env_source=primary\nlocal_env.TEST_API_KEY=local-token\n");
    file_put_contents($root.'/.ai-harness.config', "runtime=herd\nsail_services=mysql\nsite_secure=false\n");
    file_put_contents($root.'/artisan', '<?php');
    write_executable($root.'/fake-bin/herd', "#!/usr/bin/env bash\nprintf '%s\\n' \"\$*\" >> herd.log\n");
    write_executable($root.'/vendor/bin/sail', "#!/usr/bin/env bash\nexit 0\n");

    harness_process(['setup'], $root, [
        'PATH' => $root.'/fake-bin'.PATH_SEPARATOR.getenv('PATH'),
        'FORWARD_DB_PORT' => '3317',
    ])->mustRun();

    expect(file_get_contents($root.'/.env'))->toContain('APP_KEY=original-key', 'TEST_API_KEY=local-token', 'DB_PORT=3317', 'DB_DATABASE='.DatabaseName::forPath($root))
        ->and(file_get_contents($root.'/.env'))->not->toContain('APP_URL=http://primary.test')
        ->and(file_get_contents($root.'/.env.testing'))->toContain('DB_DATABASE='.DatabaseName::testingForPath($root), 'DB_PORT=3317')
        ->and(file_get_contents($root.'/herd.log'))->not->toContain('key:generate')
        ->and(file_get_contents($primary.'/.env'))->toBe($original);
});

test('process environment source selects the original environment during setup', function (): void {
    [$primary, $root] = environment_source_fixture();
    file_put_contents($primary.'/.env', "APP_KEY=original-key\nTEST_API_KEY=synthetic-token\n");
    file_put_contents($root.'/.ai-harness.config', 'env_source=example');
    harness_process(['setup'], $root, ['AI_HARNESS_ENV_SOURCE' => 'primary'])->mustRun();
    expect(file_get_contents($root.'/.env'))->toBe(file_get_contents($primary.'/.env'));
});

test('database discovery uses the selected primary environment before setup writes files', function (): void {
    [$primary, $root] = environment_source_fixture();
    file_put_contents($primary.'/.env', "DB_CONNECTION=mysql\nDB_HOST=primary.invalid\nDB_PORT=3317\nDB_USERNAME=local-user\n");
    $loader = new ConfigLoader;
    expect($loader->load($root)->existingDatabase)->toBeNull();
    $config = $loader->load($root, ['env_source' => 'primary']);
    expect($config->existingDatabase?->host)->toBe('primary.invalid')
        ->and($config->existingDatabase?->port)->toBe(3317)
        ->and($config->existingDatabase?->username)->toBe('local-user')
        ->and($root.'/.env')->not->toBeFile();
});

foreach ([['--env-source=unknown'], ['--env-source=']] as $options) {
    test('invalid environment source parameters fail before writing integration files: '.implode(' ', $options), function () use ($options): void {
        $root = temp_directory('harness-invalid-env-source');
        $process = harness_process(['init', ...$options], $root);
        $process->run();
        expect($process->getExitCode())->toBe(1)
            ->and($process->getOutput().$process->getErrorOutput())->toContain('env_source must be example or primary')
            ->and($root.'/.ai-harness.config')->not->toBeFile()
            ->and($root.'/.codex')->not->toBeDirectory();
    });
}

foreach (['missing', 'standalone', 'unregistered'] as $condition) {
    test('primary mode falls back to the example: '.$condition, function () use ($condition): void {
        [$primary, $root] = environment_source_fixture();
        file_put_contents($root.'/.ai-harness.config.local', 'env_source=primary');

        if ($condition !== 'missing') {
            file_put_contents($primary.'/.env', 'TEST_API_KEY=must-not-copy');
            if ($condition === 'standalone') {
                unlink($root.'/.git');
            } else {
                file_put_contents($primary.'/.git/worktrees/fixture/gitdir', '/unrelated/.git');
            }
        }

        harness_process(['setup'], $root)->mustRun();
        expect(file_get_contents($root.'/.env'))->toBe(file_get_contents($root.'/.env.example'));
    });
}

test('primary mode refuses symbolic link sources without changing the worktree', function (): void {
    [$primary, $root] = environment_source_fixture();
    file_put_contents($primary.'/.ai-harness.config.local', 'env_source=primary');
    $outside = temp_file('harness-env-outside');
    file_put_contents($outside, 'TEST_API_KEY=must-not-copy');
    symlink($outside, $primary.'/.env');

    $process = harness_process(['setup'], $root);
    $process->run();
    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput().$process->getErrorOutput())->toContain('symbolic link')
        ->and($root.'/.env')->not->toBeFile();
});

test('cloud setup uses the example even when primary copying is configured', function (): void {
    [$primary, $root] = environment_source_fixture();
    file_put_contents($primary.'/.env', "APP_KEY=original-key\nTEST_API_KEY=must-not-copy\n");
    file_put_contents($root.'/.ai-harness.config', "env_source=primary\ncloud_services=\n");
    file_put_contents($root.'/composer.json', '{}');
    file_put_contents($root.'/composer.lock', '{}');
    write_executable($root.'/fake-bin/composer', "#!/usr/bin/env bash\nexit 0\n");

    harness_process(['setup'], $root, [
        'AI_HARNESS_ENV' => 'codex-cloud',
        'PATH' => $root.'/fake-bin'.PATH_SEPARATOR.getenv('PATH'),
    ])->mustRun();

    expect(file_get_contents($root.'/.env'))->toBe(file_get_contents($root.'/.env.example'));
});

test('preflight checks the copied source even when the worktree has no example', function (): void {
    [$primary, $root] = environment_source_fixture();
    unlink($root.'/.env.example');
    file_put_contents($primary.'/.ai-harness.config.local', "env_source=primary\nlocal_env.DB_PORT=3307\n");
    file_put_contents($primary.'/.env', "APP_KEY=original-key\nDB_URL=mysql://private.invalid/shared\n");

    $process = harness_process(['setup'], $root);
    $process->run();
    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput().$process->getErrorOutput())->toContain('DB_URL')
        ->and($process->getOutput().$process->getErrorOutput())->not->toContain('private.invalid')
        ->and($root.'/.env')->not->toBeFile();

    file_put_contents($primary.'/.ai-harness.config.local', "env_source=primary\nlocal_env.DB_PORT=3307\nlocal_env.DB_URL=\n");
    harness_process(['setup'], $root)->mustRun();
    expect(file_get_contents($root.'/.env'))->toContain('DB_PORT=3307', "DB_URL=\n");
});
