<?php

use Symfony\Component\Process\Process;

test('codex setup and cleanup link and unlink herd workspaces when herd is enabled', function (): void {
    $path = temp_directory('ai-harness-herd');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
        '--with' => ['herd'],
    ])->assertSuccessful();

    $herdLog = temp_file('herd-log');
    $fakeBin = $path.'/fake-bin';

    mkdir($fakeBin, 0755, true);
    file_put_contents($fakeBin.'/herd', <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$HERD_LOG"
BASH);
    chmod($fakeBin.'/herd', 0755);

    run_local_environment($path, 'setup', $fakeBin, $herdLog)->mustRun();
    run_local_environment($path, 'cleanup', $fakeBin, $herdLog)->mustRun();

    $log = file($herdLog, FILE_IGNORE_NEW_LINES);
    $siteName = emitted_herd_site_name($herdLog);

    expect($log)
        ->toContain('link '.$siteName.' --no-interaction')
        ->toContain('unlink '.$siteName);
});

test('herd workspace automation is disabled by default', function (): void {
    $path = temp_directory('ai-harness-herd-disabled');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    $herdLog = temp_file('herd-log');
    $fakeBin = $path.'/fake-bin';

    mkdir($fakeBin, 0755, true);
    file_put_contents($fakeBin.'/herd', <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$HERD_LOG"
BASH);
    chmod($fakeBin.'/herd', 0755);

    run_local_environment($path, 'setup', $fakeBin, $herdLog)->mustRun();
    run_local_environment($path, 'cleanup', $fakeBin, $herdLog)->mustRun();

    expect(trim((string) file_get_contents($herdLog)))->toBe('');
});

test('herd workspace names include a stable suffix from the full worktree path', function (): void {
    $root = temp_directory('ai-harness-collision');
    $firstPath = $root.'/first/shared/project';
    $secondPath = $root.'/second/shared/project';

    mkdir($firstPath, 0755, true);
    mkdir($secondPath, 0755, true);

    pending_artisan('ai-harness:update', [
        '--path' => $firstPath,
        '--with' => ['herd'],
    ])->assertSuccessful();

    pending_artisan('ai-harness:update', [
        '--path' => $secondPath,
        '--with' => ['herd'],
    ])->assertSuccessful();

    $herdLog = temp_file('herd-log');
    $fakeBin = $root.'/fake-bin';

    mkdir($fakeBin, 0755, true);
    file_put_contents($fakeBin.'/herd', <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$HERD_LOG"
BASH);
    chmod($fakeBin.'/herd', 0755);

    run_local_environment($firstPath, 'setup', $fakeBin, $herdLog)->mustRun();
    run_local_environment($secondPath, 'setup', $fakeBin, $herdLog)->mustRun();

    $log = file($herdLog, FILE_IGNORE_NEW_LINES);
    $firstSiteName = emitted_herd_site_name($herdLog, 0);
    $secondSiteName = emitted_herd_site_name($herdLog, 1);

    expect($log)
        ->toContain('link '.$firstSiteName.' --no-interaction')
        ->toContain('link '.$secondSiteName.' --no-interaction')
        ->and($firstSiteName)
        ->not->toBe($secondSiteName);
});

test('herd workspace names are capped to a valid dns label length', function (): void {
    $root = temp_directory('ai-harness-long-herd');
    $path = $root.'/'.str_repeat('parent-', 12).'/'.str_repeat('project-', 12);

    mkdir($path, 0755, true);

    pending_artisan('ai-harness:update', [
        '--path' => $path,
        '--with' => ['herd'],
    ])->assertSuccessful();

    $herdLog = temp_file('herd-log');
    $fakeBin = $root.'/fake-bin';

    mkdir($fakeBin, 0755, true);
    file_put_contents($fakeBin.'/herd', <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$HERD_LOG"
BASH);
    chmod($fakeBin.'/herd', 0755);

    run_local_environment($path, 'setup', $fakeBin, $herdLog)->mustRun();

    $siteName = emitted_herd_site_name($herdLog);

    expect(strlen($siteName) <= 63)->toBeTrue()
        ->and($siteName)->toEndWith('-'.path_checksum($path))
        ->and(file_get_contents($herdLog))
        ->toContain('link '.$siteName.' --no-interaction');
});

test('herd workspace cleanup surfaces unexpected unlink failures', function (): void {
    $path = temp_directory('ai-harness-herd-failure');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
        '--with' => ['herd'],
    ])->assertSuccessful();

    $herdLog = temp_file('herd-log');
    $fakeBin = $path.'/fake-bin';

    mkdir($fakeBin, 0755, true);
    file_put_contents($fakeBin.'/herd', <<<'BASH'
#!/usr/bin/env bash
if [[ "${1:-}" == "unlink" ]]; then
    printf 'herd database is unavailable\n' >&2
    exit 37
fi

printf '%s\n' "$*" >> "$HERD_LOG"
BASH);
    chmod($fakeBin.'/herd', 0755);

    $process = run_local_environment($path, 'cleanup', $fakeBin, $herdLog);
    $process->run();

    expect($process->getExitCode())->toBe(37)
        ->and($process->getErrorOutput())->toContain('herd database is unavailable');
});

test('herd workspace cleanup ignores missing links', function (): void {
    $path = temp_directory('ai-harness-herd-missing');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
        '--with' => ['herd'],
    ])->assertSuccessful();

    $herdLog = temp_file('herd-log');
    $fakeBin = $path.'/fake-bin';

    mkdir($fakeBin, 0755, true);
    file_put_contents($fakeBin.'/herd', <<<'BASH'
#!/usr/bin/env bash
if [[ "${1:-}" == "unlink" ]]; then
    printf 'The requested Herd link was not found.\n' >&2
    exit 1
fi

printf '%s\n' "$*" >> "$HERD_LOG"
BASH);
    chmod($fakeBin.'/herd', 0755);

    run_local_environment($path, 'cleanup', $fakeBin, $herdLog)->mustRun();
});

test('codex setup configures isolated sqlite app and testing databases, app url, and migrations', function (): void {
    $path = temp_directory('ai-harness-provision');

    file_put_contents($path.'/.env.example', implode("\n", [
        'APP_URL=http://example.test',
        'APP_KEY=',
        'DB_CONNECTION=sqlite',
        'DB_DATABASE=database/database.sqlite',
        '',
    ]));
    file_put_contents($path.'/artisan', '');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
        '--with' => ['herd'],
    ])->assertSuccessful();

    fake_artisan_helper($path);

    $herdLog = temp_file('herd-log');
    $fakeBin = $path.'/fake-bin';

    mkdir($fakeBin, 0755, true);
    file_put_contents($fakeBin.'/herd', <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$HERD_LOG"
BASH);
    chmod($fakeBin.'/herd', 0755);

    run_local_environment($path, 'setup', $fakeBin, $herdLog)->mustRun();

    $siteName = emitted_herd_site_name($herdLog);
    $database = env_value($path, 'DB_DATABASE');
    $testingDatabase = env_value($path, 'AI_HARNESS_TEST_DB_DATABASE');
    $databasePath = $path.'/'.$database;
    $testingDatabasePath = $path.'/'.$testingDatabase;

    expect(file_get_contents($path.'/.env'))
        ->toContain('APP_URL=http://'.$siteName.'.test')
        ->toContain('DB_DATABASE='.$database)
        ->toContain('AI_HARNESS_TEST_DB_DATABASE='.$testingDatabase)
        ->and($database)
        ->toStartWith('database/')
        ->toEndWith('_'.path_checksum($path).'.sqlite')
        ->and($testingDatabase)
        ->toStartWith('database/')
        ->toEndWith('_testing_'.path_checksum($path).'.sqlite')
        ->and($databasePath)->toBeFile()
        ->and($testingDatabasePath)->toBeFile()
        ->and(file_get_contents($path.'/artisan.log'))
        ->toContain('key:generate --ansi')
        ->toContain('migrate --force --ansi')
        ->toContain('ai-harness:doctor');
});

test('codex cleanup removes isolated sqlite app and testing databases before unlinking herd', function (): void {
    $path = temp_directory('ai-harness-cleanup');

    file_put_contents($path.'/.env', implode("\n", [
        'APP_URL=http://'.expected_herd_site_name($path).'.test',
        'DB_CONNECTION=sqlite',
        'DB_DATABASE=database/'.expected_worktree_database_name($path).'.sqlite',
        'AI_HARNESS_TEST_DB_DATABASE=database/'.expected_worktree_testing_database_name($path).'.sqlite',
        '',
    ]));
    mkdir($path.'/database', 0755, true);
    file_put_contents($path.'/database/'.expected_worktree_database_name($path).'.sqlite', '');
    file_put_contents($path.'/database/'.expected_worktree_testing_database_name($path).'.sqlite', '');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
        '--with' => ['herd'],
    ])->assertSuccessful();

    fake_artisan_helper($path);

    $herdLog = temp_file('herd-log');
    $fakeBin = $path.'/fake-bin';

    mkdir($fakeBin, 0755, true);
    file_put_contents($fakeBin.'/herd', <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$HERD_LOG"
BASH);
    chmod($fakeBin.'/herd', 0755);

    run_local_environment($path, 'cleanup', $fakeBin, $herdLog)->mustRun();

    expect($path.'/database/'.expected_worktree_database_name($path).'.sqlite')
        ->not->toBeFile()
        ->and($path.'/database/'.expected_worktree_testing_database_name($path).'.sqlite')
        ->not->toBeFile()
        ->and(file_get_contents($herdLog))
        ->toContain('unlink '.expected_herd_site_name($path));
});

test('mysql worktree databases are created through sail when sail is available', function (): void {
    $path = temp_directory('ai-harness-sail-database');

    file_put_contents($path.'/.env.example', implode("\n", [
        'APP_KEY=',
        'DB_CONNECTION=mysql',
        'DB_HOST=mysql',
        'DB_PORT=3306',
        'DB_DATABASE=laravel',
        'DB_USERNAME=sail',
        'DB_PASSWORD=password',
        '',
    ]));
    file_put_contents($path.'/artisan', '');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    fake_artisan_helper($path);

    $fakeBin = $path.'/fake-bin';
    $sailLog = temp_file('sail-log');
    $herdLog = temp_file('herd-log');

    mkdir($fakeBin, 0755, true);
    mkdir($path.'/vendor/bin', 0755, true);

    file_put_contents($fakeBin.'/docker', <<<'BASH'
#!/usr/bin/env bash
if [[ "${1:-}" == "info" ]]; then
    exit 0
fi

exit 1
BASH);
    chmod($fakeBin.'/docker', 0755);

    file_put_contents($path.'/vendor/bin/sail', <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$SAIL_LOG"
printf 'database=%s\n' "${AI_HARNESS_DB_DATABASE:-}" >> "$SAIL_LOG"
BASH);
    chmod($path.'/vendor/bin/sail', 0755);

    run_local_environment($path, 'setup', $fakeBin, $herdLog, [
        'SAIL_LOG' => $sailLog,
    ])->mustRun();

    expect(file_get_contents($path.'/.env'))
        ->toContain('DB_DATABASE='.expected_worktree_database_name($path))
        ->toContain('AI_HARNESS_TEST_DB_DATABASE='.expected_worktree_testing_database_name($path))
        ->and(file_get_contents($sailLog))
        ->toContain('php -r')
        ->toContain('database='.expected_worktree_database_name($path))
        ->toContain('database='.expected_worktree_testing_database_name($path));
});

test('mysql worktree app and testing databases are dropped through sail during cleanup', function (): void {
    $path = temp_directory('ai-harness-sail-database-cleanup');

    file_put_contents($path.'/.env', implode("\n", [
        'DB_CONNECTION=mysql',
        'DB_HOST=mysql',
        'DB_PORT=3306',
        'DB_DATABASE='.expected_worktree_database_name($path),
        'DB_USERNAME=sail',
        'DB_PASSWORD=password',
        'AI_HARNESS_TEST_DB_DATABASE='.expected_worktree_testing_database_name($path),
        '',
    ]));

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    fake_artisan_helper($path);

    $fakeBin = $path.'/fake-bin';
    $sailLog = temp_file('sail-log');
    $herdLog = temp_file('herd-log');

    mkdir($fakeBin, 0755, true);
    mkdir($path.'/vendor/bin', 0755, true);

    file_put_contents($fakeBin.'/docker', <<<'BASH'
#!/usr/bin/env bash
if [[ "${1:-}" == "info" ]]; then
    exit 0
fi

exit 1
BASH);
    chmod($fakeBin.'/docker', 0755);

    file_put_contents($path.'/vendor/bin/sail', <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$SAIL_LOG"
printf 'database=%s\n' "${AI_HARNESS_DB_DATABASE:-}" >> "$SAIL_LOG"
BASH);
    chmod($path.'/vendor/bin/sail', 0755);

    run_local_environment($path, 'cleanup', $fakeBin, $herdLog, [
        'SAIL_LOG' => $sailLog,
    ])->mustRun();

    expect(file_get_contents($sailLog))
        ->toContain('database='.expected_worktree_testing_database_name($path))
        ->toContain('database='.expected_worktree_database_name($path));
});

test('codex cleanup is run against the generated worktree path', function (): void {
    $path = temp_directory('ai-harness-environment');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    expect(file_get_contents($path.'/.codex/environments/environment.toml'))
        ->toContain(': "${CODEX_WORKTREE_PATH:?CODEX_WORKTREE_PATH is required}"')
        ->toContain('bash "$CODEX_SOURCE_TREE_PATH/.codex/scripts/local-environment.sh" cleanup');
});

/**
 * @param  array<string, string>  $environment
 */
function run_local_environment(string $path, string $action, string $fakeBin, string $herdLog, array $environment = []): Process
{
    return new Process(
        ['bash', $path.'/.codex/scripts/local-environment.sh', $action],
        $path,
        [
            'CODEX_WORKTREE_PATH' => $path,
            'HERD_LOG' => $herdLog,
            'PATH' => $fakeBin.PATH_SEPARATOR.getenv('PATH'),
            'WORKTREE_PROFILE' => 'codex',
        ] + $environment,
    );
}

function expected_herd_site_name(string $path): string
{
    $hash = path_checksum($path);

    $name = basename($path).'-'.basename(dirname($path));
    $name = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
    $name = trim($name, '-');
    $name = substr($name, 0, 63 - strlen($hash) - 1);
    $name = rtrim($name, '-');

    return ($name !== '' ? $name : 'codex-worktree').'-'.$hash;
}

function emitted_herd_site_name(string $herdLog, int $index = 0): string
{
    preg_match_all('/^link ([^ ]+) --no-interaction$/m', (string) file_get_contents($herdLog), $matches);

    if (! isset($matches[1][$index])) {
        throw new RuntimeException('Unable to read emitted Herd site name.');
    }

    return $matches[1][$index];
}

function env_value(string $path, string $key): string
{
    $env = (string) file_get_contents($path.'/.env');

    if (preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $env, $matches) !== 1) {
        throw new RuntimeException("Unable to read {$key} from .env.");
    }

    return $matches[1];
}

function expected_worktree_database_name(string $path): string
{
    $hash = path_checksum($path);
    $base = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', basename($path)));
    $base = trim($base, '_');

    return substr($base, 0, 64 - strlen($hash) - 1).'_'.$hash;
}

function expected_worktree_testing_database_name(string $path): string
{
    $hash = path_checksum($path);
    $base = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', basename($path)));
    $base = trim($base, '_');
    $suffix = '_testing_';

    return substr($base, 0, 64 - strlen($hash) - strlen($suffix)).$suffix.$hash;
}

function path_checksum(string $path): string
{
    $checksum = new Process(['cksum'], null, null, $path);
    $checksum->mustRun();

    if (preg_match('/^(\d+)\s+/', $checksum->getOutput(), $matches) !== 1) {
        throw new RuntimeException('Unable to derive expected path hash.');
    }

    return $matches[1];
}

function fake_artisan_helper(string $path): void
{
    file_put_contents($path.'/.dev/bin/ai-harness', <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> artisan.log
BASH);
    chmod($path.'/.dev/bin/ai-harness', 0755);
}
