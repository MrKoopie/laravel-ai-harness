<?php

use Symfony\Component\Process\Process;

test('codex setup and cleanup link and unlink herd workspaces when herd is enabled', function (): void {
    $path = temp_directory('ai-harness-herd');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
        '--with' => ['herd'],
    ])->assertSuccessful();

    $herdLog = temp_file('herd-log');
    $fakeBin = write_fake_herd($path);

    run_local_environment($path, 'setup', $fakeBin, $herdLog, [
        'REAL_PHP' => PHP_BINARY,
    ])->mustRun();
    run_local_environment($path, 'cleanup', $fakeBin, $herdLog)->mustRun();

    $log = file($herdLog, FILE_IGNORE_NEW_LINES);
    $siteName = emitted_herd_site_name($herdLog);

    expect($log)
        ->toContain('link '.$siteName.' --no-interaction')
        ->toContain('secure '.$siteName)
        ->toContain('unsecure '.$siteName)
        ->toContain('unlink '.$siteName);
});

test('codex cleanup unlinks a recorded herd site after the feature is disabled', function (): void {
    $path = temp_directory('ai-harness-herd-disabled-cleanup');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
        '--with' => ['herd'],
    ])->assertSuccessful();

    $herdLog = temp_file('herd-log');
    $fakeBin = write_fake_herd($path);

    run_local_environment($path, 'setup', $fakeBin, $herdLog)->mustRun();

    expect($path.'/.codex/local-environment-state/herd-linked-site')->toBeFile();

    run_local_environment($path, 'cleanup', $fakeBin, $herdLog, [
        'AI_HARNESS_HERD' => 'false',
    ])->mustRun();

    expect(file_get_contents($herdLog))
        ->toContain('unlink '.expected_herd_site_name($path))
        ->and($path.'/.codex/local-environment-state')->not->toBeDirectory();
});

test('codex cleanup uses a legacy managed app url when no linked-site marker exists', function (): void {
    $path = temp_directory('ai-harness-herd-legacy-cleanup');
    $site = expected_herd_site_name($path);

    file_put_contents($path.'/.env', "APP_URL=https://{$site}.test\nDB_CONNECTION=sqlite\n");

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    $herdLog = temp_file('herd-log');
    $fakeBin = write_fake_herd($path);

    run_local_environment($path, 'cleanup', $fakeBin, $herdLog)->mustRun();

    expect(file_get_contents($herdLog))
        ->toContain('unsecure '.$site)
        ->toContain('unlink '.$site);
});

test('codex cleanup unlinks when secure fails after herd link succeeds', function (): void {
    $path = temp_directory('ai-harness-herd-secure-failure');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
        '--with' => ['herd'],
    ])->assertSuccessful();

    $herdLog = temp_file('herd-log');
    $fakeBin = write_fake_herd($path, <<<'BASH'
if [[ "${1:-}" == "secure" ]]; then
    exit 37
fi
BASH);

    $setup = run_local_environment($path, 'setup', $fakeBin, $herdLog);
    $setup->run();

    expect($setup->getExitCode())->toBe(37)
        ->and($path.'/.codex/local-environment-state/herd-linked-site')->toBeFile();

    run_local_environment($path, 'cleanup', $fakeBin, $herdLog)->mustRun();

    expect(file_get_contents($herdLog))
        ->toContain('unlink '.expected_herd_site_name($path));
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

if [[ "${1:-}" == "php" && -n "${AI_HARNESS_TEST_DB_DATABASE:-}" ]]; then
    shift
    "$REAL_PHP" "$@"
fi
BASH);
    chmod($fakeBin.'/herd', 0755);

    run_local_environment($path, 'setup', $fakeBin, $herdLog, [
        'REAL_PHP' => PHP_BINARY,
    ])->mustRun();
    run_local_environment($path, 'cleanup', $fakeBin, $herdLog)->mustRun();

    expect(trim((string) file_get_contents($herdLog)))->toBe('');
});

test('herd workspace automation can be enabled at runtime for generated scripts', function (): void {
    $path = temp_directory('ai-harness-herd-runtime');

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

    run_local_environment($path, 'setup', $fakeBin, $herdLog, [
        'AI_HARNESS_HERD_WORKSPACE_ENABLED' => '1',
    ])->mustRun();

    expect(file_get_contents($herdLog))
        ->toContain('link '.expected_herd_site_name($path).' --no-interaction');
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

if [[ "${1:-}" == "php" && -n "${AI_HARNESS_TEST_DB_DATABASE:-}" ]]; then
    shift
    "$REAL_PHP" "$@"
fi
BASH);
    chmod($fakeBin.'/herd', 0755);

    run_local_environment($path, 'setup', $fakeBin, $herdLog, [
        'REAL_PHP' => PHP_BINARY,
    ])->mustRun();

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
    $phpunit = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit>
    <php>
        <env name="DB_CONNECTION" value="sqlite"/>
        <env name="DB_DATABASE" value=":memory:"/>
        <env name="DB_URL" value=""/>
    </php>
</phpunit>
XML;
    file_put_contents($path.'/phpunit.xml', $phpunit);

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

if [[ "${1:-}" == "php" && -n "${AI_HARNESS_TEST_DB_DATABASE:-}" ]]; then
    shift
    "$REAL_PHP" "$@"
fi
BASH);
    chmod($fakeBin.'/herd', 0755);

    run_local_environment($path, 'setup', $fakeBin, $herdLog, [
        'REAL_PHP' => PHP_BINARY,
    ])->mustRun();

    $siteName = emitted_herd_site_name($herdLog);
    $database = env_value($path, 'DB_DATABASE');
    $testingDatabase = env_value($path, 'AI_HARNESS_TEST_DB_DATABASE');
    $databasePath = $path.'/'.$database;
    $testingDatabasePath = $path.'/'.$testingDatabase;
    $phpunitConfig = $path.'/.ai-harness.phpunit.xml';

    expect(file_get_contents($path.'/.env'))
        ->toContain('APP_URL=https://'.$siteName.'.test')
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
        ->and(file_get_contents($phpunitConfig))
        ->toContain('name="DB_CONNECTION" value="sqlite" force="true"')
        ->toContain('name="DB_DATABASE" value="'.$testingDatabase.'" force="true"')
        ->toContain('name="DB_URL" value="" force="true"')
        ->and(file_get_contents($path.'/phpunit.xml'))
        ->toBe($phpunit)
        ->and(file_get_contents($path.'/artisan.log'))
        ->toContain('key:generate --ansi')
        ->toContain('migrate --force --ansi')
        ->toContain('migrate --env=testing --force --ansi')
        ->toContain('DB_DATABASE='.$testingDatabase)
        ->toContain('ai-harness:doctor');
});

test('codex setup clears db url before running app migrations', function (): void {
    $path = temp_directory('ai-harness-app-db-url');

    file_put_contents($path.'/.env.example', implode("\n", [
        'APP_KEY=',
        'DB_CONNECTION=sqlite',
        'DB_DATABASE=database/database.sqlite',
        'DB_URL=mysql://production.example/app',
        '',
    ]));
    file_put_contents($path.'/artisan', '');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    fake_artisan_helper($path);

    $herdLog = temp_file('herd-log');
    $fakeBin = $path.'/fake-bin';

    mkdir($fakeBin, 0755, true);

    run_local_environment($path, 'setup', $fakeBin, $herdLog, [
        'DB_URL' => 'mysql://ambient.example/app',
    ])->mustRun();

    expect(file_get_contents($path.'/.env'))
        ->toContain('DB_URL=')
        ->not()->toContain('DB_URL=mysql://production.example/app')
        ->and(file_get_contents($path.'/artisan.log'))
        ->toContain("migrate --force --ansi\nDB_CONNECTION= DB_DATABASE= DB_URL=\n");
});

test('codex setup fails fast for unsupported database connections', function (): void {
    $path = temp_directory('ai-harness-unsupported-db');

    file_put_contents($path.'/.env.example', implode("\n", [
        'APP_KEY=',
        'DB_CONNECTION=pgsql',
        'DB_DATABASE=app',
        '',
    ]));
    file_put_contents($path.'/artisan', '');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    fake_artisan_helper($path);

    $herdLog = temp_file('herd-log');
    $fakeBin = $path.'/fake-bin';

    mkdir($fakeBin, 0755, true);

    $process = run_local_environment($path, 'setup', $fakeBin, $herdLog);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('unsupported DB_CONNECTION=pgsql');
});

test('codex setup resolves herd from the well known macos path', function (): void {
    $path = temp_directory('ai-harness-herd-known-path');
    $home = temp_directory('ai-harness-home');
    $herdDirectory = $home.'/Library/Application Support/Herd/bin';

    file_put_contents($path.'/.env.example', implode("\n", [
        'APP_URL=http://example.test',
        'APP_KEY=base64:already-set',
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
    mkdir($herdDirectory, 0755, true);
    file_put_contents($herdDirectory.'/herd', <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$HERD_LOG"

if [[ "${1:-}" == "php" && -n "${AI_HARNESS_TEST_DB_DATABASE:-}" ]]; then
    shift
    "$REAL_PHP" "$@"
fi
BASH);
    chmod($herdDirectory.'/herd', 0755);

    run_local_environment($path, 'setup', $fakeBin, $herdLog, [
        'HOME' => $home,
        'PATH' => $fakeBin.PATH_SEPARATOR.'/usr/bin:/bin:/usr/sbin:/sbin',
        'REAL_PHP' => PHP_BINARY,
    ])->mustRun();

    $siteName = emitted_herd_site_name($herdLog);

    expect(file_get_contents($herdLog))
        ->toContain('link '.$siteName.' --no-interaction')
        ->toContain('secure '.$siteName)
        ->and(file_get_contents($path.'/.env'))
        ->toContain('APP_URL=https://'.$siteName.'.test');
});

test('codex setup keeps the baked herd feature when project config only provides the default false value', function (): void {
    $path = temp_directory('ai-harness-herd-baked-feature');

    mkdir($path.'/config', 0755, true);
    file_put_contents($path.'/config/ai-harness.php', <<<'PHP'
<?php

return [
    'features' => [
        'herd' => env('AI_HARNESS_HERD', false),
    ],
];
PHP);
    file_put_contents($path.'/.env.example', implode("\n", [
        'APP_URL=http://example.test',
        'APP_KEY=base64:already-set',
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

    expect(file_get_contents($herdLog))
        ->toContain('link '.$siteName.' --no-interaction')
        ->toContain('secure '.$siteName)
        ->and(file_get_contents($path.'/.env'))
        ->toContain('APP_URL=https://'.$siteName.'.test');
});

test('codex setup enables herd from runtime project config when the baked flag is disabled', function (): void {
    $path = temp_directory('ai-harness-herd-runtime-config');

    mkdir($path.'/config', 0755, true);
    file_put_contents($path.'/config/ai-harness.php', <<<'PHP'
<?php

return [
    'features' => [
        'herd' => true,
    ],
];
PHP);
    file_put_contents($path.'/.env.example', implode("\n", [
        'APP_URL=http://example.test',
        'APP_KEY=base64:already-set',
        'DB_CONNECTION=sqlite',
        'DB_DATABASE=database/database.sqlite',
        '',
    ]));
    file_put_contents($path.'/artisan', '');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
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

    expect(file_get_contents($herdLog))
        ->toContain('link '.$siteName.' --no-interaction')
        ->toContain('secure '.$siteName)
        ->and(file_get_contents($path.'/.env'))
        ->toContain('APP_URL=https://'.$siteName.'.test');
});

test('codex setup still sets the per-worktree app url when herd is enabled but unavailable', function (): void {
    $path = temp_directory('ai-harness-herd-missing-setup');
    $home = temp_directory('ai-harness-empty-home');

    file_put_contents($path.'/.env.example', implode("\n", [
        'APP_URL=http://shared.test',
        'APP_KEY=base64:already-set',
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

    $process = run_local_environment($path, 'setup', $fakeBin, $herdLog, [
        'AI_HARNESS_HERD_OS' => 'Darwin',
        'HOME' => $home,
        'PATH' => $fakeBin.PATH_SEPARATOR.'/usr/bin:/bin:/usr/sbin:/sbin',
        'REAL_PHP' => PHP_BINARY,
    ]);
    $process->mustRun();

    // On macOS (platform pinned via AI_HARNESS_HERD_OS) APP_URL is gated on the
    // stable herd_workspace_requested feature flag rather than the transient herd
    // binary check, so it stays symmetric with the always-isolated database even
    // when the Herd CLI cannot be resolved during provisioning. The Herd site
    // itself is only linked once the CLI is available, so setup records a pending
    // signal the wrapper uses to retry the link later.
    expect($process->getErrorOutput())
        ->toContain('Herd workspace requested but Herd CLI could not be resolved; the Herd site was not linked. APP_URL already targets the per-worktree Herd site; start Herd and the next session links it automatically.')
        ->and(file_get_contents($herdLog))
        ->not()->toContain('link ')
        ->and(file_get_contents($path.'/.env'))
        ->toContain('APP_URL=https://'.expected_herd_site_name($path).'.test')
        ->toContain('DB_DATABASE=database/'.expected_worktree_database_name($path).'.sqlite')
        ->not()->toContain('APP_URL=http://shared.test')
        ->and($path.'/.codex/local-environment-state/herd-link-pending')->toBeFile();
});

test('codex cleanup clears a pending herd link when no site was ever linked', function (): void {
    $path = temp_directory('ai-harness-herd-pending-cleanup');
    $home = temp_directory('ai-harness-empty-home');

    file_put_contents($path.'/.env.example', implode("\n", [
        'APP_URL=http://shared.test',
        'APP_KEY=base64:already-set',
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
    $environment = [
        'AI_HARNESS_HERD_OS' => 'Darwin',
        'HOME' => $home,
        'PATH' => $fakeBin.PATH_SEPARATOR.'/usr/bin:/bin:/usr/sbin:/sbin',
        'REAL_PHP' => PHP_BINARY,
    ];

    mkdir($fakeBin, 0755, true);

    run_local_environment($path, 'setup', $fakeBin, $herdLog, $environment)->mustRun();

    expect($path.'/.codex/local-environment-state/herd-link-pending')->toBeFile()
        ->and($path.'/.codex/local-environment-state/herd-linked-site')->not->toBeFile();

    $cleanup = run_local_environment($path, 'cleanup', $fakeBin, $herdLog, $environment);
    $cleanup->mustRun();

    expect($cleanup->getErrorOutput())
        ->not()->toContain('unable to unlink recorded Herd site')
        ->and($path.'/.codex/local-environment-state')->not->toBeDirectory();
});

test('codex link-herd links the deferred site and clears the pending signal once herd is available', function (): void {
    $path = temp_directory('ai-harness-herd-link-retry');
    $home = temp_directory('ai-harness-empty-home');

    file_put_contents($path.'/.env.example', implode("\n", [
        'APP_URL=http://shared.test',
        'APP_KEY=base64:already-set',
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

    run_local_environment($path, 'setup', $fakeBin, $herdLog, [
        'AI_HARNESS_HERD_OS' => 'Darwin',
        'HOME' => $home,
        'PATH' => $fakeBin.PATH_SEPARATOR.'/usr/bin:/bin:/usr/sbin:/sbin',
        'REAL_PHP' => PHP_BINARY,
    ])->mustRun();

    expect($path.'/.codex/local-environment-state/herd-link-pending')->toBeFile();

    file_put_contents($fakeBin.'/herd', <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$HERD_LOG"
BASH);
    chmod($fakeBin.'/herd', 0755);

    run_local_environment($path, 'link-herd', $fakeBin, $herdLog)->mustRun();

    expect(file_get_contents($herdLog))
        ->toContain('link '.expected_herd_site_name($path).' --no-interaction')
        ->toContain('secure '.expected_herd_site_name($path))
        ->and($path.'/.codex/local-environment-state/herd-link-pending')->not->toBeFile();
});

test('codex heal-env re-asserts the per-worktree app url and database after a clobbered env', function (): void {
    $path = temp_directory('ai-harness-heal-env');
    $home = temp_directory('ai-harness-empty-home');

    file_put_contents($path.'/.env.example', implode("\n", [
        'APP_URL=http://shared.test',
        'APP_KEY=base64:already-set',
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

    $herdEnvironment = [
        'AI_HARNESS_HERD_OS' => 'Darwin',
        'HOME' => $home,
        'PATH' => $fakeBin.PATH_SEPARATOR.'/usr/bin:/bin:/usr/sbin:/sbin',
        'REAL_PHP' => PHP_BINARY,
    ];

    run_local_environment($path, 'setup', $fakeBin, $herdLog, $herdEnvironment)->mustRun();

    expect(file_get_contents($path.'/.env'))
        ->toContain('APP_URL=https://'.expected_herd_site_name($path).'.test')
        ->toContain('DB_DATABASE=database/'.expected_worktree_database_name($path).'.sqlite');

    // Simulate a recycled worktree: .worktreeinclude re-copies the source
    // checkout's .env over the provisioned one, reverting the isolated values.
    file_put_contents($path.'/.env', implode("\n", [
        'APP_URL=http://shared.test',
        'APP_KEY=base64:already-set',
        'DB_CONNECTION=sqlite',
        'DB_DATABASE=database/database.sqlite',
        '',
    ]));

    run_local_environment($path, 'heal-env', $fakeBin, $herdLog, $herdEnvironment)->mustRun();

    expect(file_get_contents($path.'/.env'))
        ->toContain('APP_URL=https://'.expected_herd_site_name($path).'.test')
        ->toContain('DB_DATABASE=database/'.expected_worktree_database_name($path).'.sqlite')
        ->not()->toContain('APP_URL=http://shared.test');
});

test('codex heal-env migrates isolated databases that had to be recreated', function (): void {
    $path = temp_directory('ai-harness-heal-recreated-databases');

    file_put_contents($path.'/.env.example', implode("\n", [
        'APP_KEY=base64:already-set',
        'DB_CONNECTION=sqlite',
        'DB_DATABASE=database/database.sqlite',
        '',
    ]));
    file_put_contents($path.'/artisan', '');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    fake_artisan_helper($path);

    $herdLog = temp_file('herd-log');
    $fakeBin = $path.'/fake-bin';
    mkdir($fakeBin, 0755, true);

    run_local_environment($path, 'setup', $fakeBin, $herdLog)->mustRun();

    unlink($path.'/database/'.expected_worktree_database_name($path).'.sqlite');
    unlink($path.'/database/'.expected_worktree_testing_database_name($path).'.sqlite');
    file_put_contents($path.'/artisan.log', '');

    run_local_environment($path, 'heal-env', $fakeBin, $herdLog)->mustRun();

    expect(file_get_contents($path.'/artisan.log'))
        ->toContain('migrate --force --ansi')
        ->toContain('migrate --env=testing --force --ansi');
});

test('codex heal-env preserves database targets recorded before a driver change', function (): void {
    $path = temp_directory('ai-harness-heal-driver-change');

    file_put_contents($path.'/.env', implode("\n", [
        'DB_CONNECTION=sqlite',
        'DB_DATABASE=database/database.sqlite',
        '',
    ]));
    mkdir($path.'/.codex/local-environment-state', 0755, true);
    file_put_contents($path.'/.codex/local-environment-state/databases.env', implode("\n", [
        'APP_DB_CONNECTION=mysql',
        'APP_DB_DATABASE=previous_app_database',
        'TEST_DB_CONNECTION=mysql',
        'TEST_DB_DATABASE=previous_testing_database',
        '',
    ]));

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    $herdLog = temp_file('herd-log');
    $fakeBin = $path.'/fake-bin';
    mkdir($fakeBin, 0755, true);

    run_local_environment($path, 'heal-env', $fakeBin, $herdLog)->mustRun();

    $state = file_get_contents($path.'/.codex/local-environment-state/databases.env');

    expect($state)
        ->toContain('DATABASE_TARGET=mysql|previous_app_database')
        ->toContain('DATABASE_TARGET=mysql|previous_testing_database')
        ->toContain('DATABASE_TARGET=sqlite|database/'.expected_worktree_database_name($path).'.sqlite')
        ->toContain('DATABASE_TARGET=sqlite|database/'.expected_worktree_testing_database_name($path).'.sqlite');
});

test('codex setup keeps the shared app url on a herd-less platform even when the herd feature is enabled', function (): void {
    $path = temp_directory('ai-harness-herd-linux-setup');
    $home = temp_directory('ai-harness-empty-home');

    file_put_contents($path.'/.env.example', implode("\n", [
        'APP_URL=http://shared.test',
        'APP_KEY=base64:already-set',
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

    // Herd only exists on macOS. On a Herd-less host (e.g. Linux/Sail) the shared
    // APP_URL is the one that can actually be served, so the worktree keeps it —
    // only the database is isolated — and no link retry or warning is recorded.
    $process = run_local_environment($path, 'setup', $fakeBin, $herdLog, [
        'AI_HARNESS_HERD_OS' => 'Linux',
        'HOME' => $home,
        'PATH' => $fakeBin.PATH_SEPARATOR.'/usr/bin:/bin:/usr/sbin:/sbin',
        'REAL_PHP' => PHP_BINARY,
    ]);
    $process->mustRun();

    expect($process->getErrorOutput())
        ->not()->toContain('Herd workspace requested but Herd CLI could not be resolved')
        ->and(file_get_contents($herdLog))
        ->not()->toContain('link ')
        ->and(file_get_contents($path.'/.env'))
        ->toContain('APP_URL=http://shared.test')
        ->toContain('DB_DATABASE=database/'.expected_worktree_database_name($path).'.sqlite')
        ->not()->toContain('APP_URL=https://')
        ->and($path.'/.codex/local-environment-state/herd-link-pending')->not->toBeFile();
});

test('codex cleanup removes generated phpunit config after wiring the generated testing database', function (): void {
    $path = temp_directory('ai-harness-phpunit-config-remove');

    file_put_contents($path.'/.env.example', implode("\n", [
        'APP_URL=http://example.test',
        'APP_KEY=',
        'DB_CONNECTION=sqlite',
        'DB_DATABASE=database/database.sqlite',
        '',
    ]));
    file_put_contents($path.'/artisan', '');
    $phpunit = legacy_phpunit_fixture();
    file_put_contents($path.'/phpunit.xml', $phpunit);

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

if [[ "${1:-}" == "php" && -n "${AI_HARNESS_TEST_DB_DATABASE:-}" ]]; then
    shift
    "$REAL_PHP" "$@"
fi
BASH);
    chmod($fakeBin.'/herd', 0755);

    run_local_environment($path, 'setup', $fakeBin, $herdLog, [
        'REAL_PHP' => PHP_BINARY,
    ])->mustRun();

    $phpunitConfig = $path.'/.ai-harness.phpunit.xml';

    expect(file_get_contents($phpunitConfig))
        ->toContain('DB_DATABASE')
        ->toContain('database/'.expected_worktree_testing_database_name($path).'.sqlite')
        ->not()->toBe($phpunit)
        ->and(file_get_contents($path.'/phpunit.xml'))->toBe($phpunit);

    run_local_environment($path, 'cleanup', $fakeBin, $herdLog)->mustRun();

    expect($phpunitConfig)->not->toBeFile()
        ->and($path.'/.codex/local-environment-state')->not->toBeDirectory();
});

test('codex setup leaves tracked phpunit edits visible in git status', function (): void {
    $path = temp_directory('ai-harness-phpunit-git-status');

    file_put_contents($path.'/.env.example', implode("\n", [
        'APP_URL=http://example.test',
        'APP_KEY=',
        'DB_CONNECTION=sqlite',
        'DB_DATABASE=database/database.sqlite',
        '',
    ]));
    file_put_contents($path.'/artisan', '');
    $phpunit = legacy_phpunit_fixture();
    file_put_contents($path.'/phpunit.xml', $phpunit);

    (new Process(['git', 'init'], $path))->mustRun();
    (new Process(['git', 'config', 'user.email', 'test@example.com'], $path))->mustRun();
    (new Process(['git', 'config', 'user.name', 'Test User'], $path))->mustRun();
    (new Process(['git', 'add', 'phpunit.xml'], $path))->mustRun();
    (new Process(['git', 'commit', '-m', 'Track phpunit'], $path))->mustRun();

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

if [[ "${1:-}" == "php" && -n "${AI_HARNESS_TEST_DB_DATABASE:-}" ]]; then
    shift
    "$REAL_PHP" "$@"
fi
BASH);
    chmod($fakeBin.'/herd', 0755);

    run_local_environment($path, 'setup', $fakeBin, $herdLog, [
        'REAL_PHP' => PHP_BINARY,
    ])->mustRun();

    $setupStatus = new Process(['git', 'status', '--short', '--', 'phpunit.xml'], $path);
    $setupStatus->mustRun();

    $generatedConfigStatus = new Process(['git', 'status', '--short', '--', '.ai-harness.phpunit.xml'], $path);
    $generatedConfigStatus->mustRun();

    expect(file_get_contents($path.'/.ai-harness.phpunit.xml'))
        ->toContain('database/'.expected_worktree_testing_database_name($path).'.sqlite')
        ->and(file_get_contents($path.'/phpunit.xml'))->toBe($phpunit)
        ->and(trim($setupStatus->getOutput()))->toBe('')
        ->and(trim($generatedConfigStatus->getOutput()))->toBe('');

    file_put_contents($path.'/phpunit.xml', str_replace('</phpunit>', "    <!-- custom phpunit edit -->\n</phpunit>", $phpunit));

    $manualEditStatus = new Process(['git', 'status', '--short', '--', 'phpunit.xml'], $path);
    $manualEditStatus->mustRun();

    expect(trim($manualEditStatus->getOutput()))->toBe('M phpunit.xml');

    run_local_environment($path, 'cleanup', $fakeBin, $herdLog)->mustRun();

    $cleanupStatus = new Process(['git', 'status', '--short', '--', 'phpunit.xml'], $path);
    $cleanupStatus->mustRun();

    expect(trim($cleanupStatus->getOutput()))->toBe('M phpunit.xml')
        ->and($path.'/.ai-harness.phpunit.xml')->not->toBeFile()
        ->and($path.'/.codex/local-environment-state')->not->toBeDirectory();
});

test('codex cleanup restores legacy phpunit backup state', function (): void {
    $path = temp_directory('ai-harness-phpunit-legacy-restore');

    $phpunit = legacy_phpunit_fixture();
    $patchedPhpunit = str_replace('value=":memory:"', 'value="database/legacy_testing.sqlite" force="true"', $phpunit);

    file_put_contents($path.'/phpunit.xml', $patchedPhpunit);

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    mkdir($path.'/.codex/local-environment-state', 0755, true);
    file_put_contents($path.'/.codex/local-environment-state/phpunit.xml.backup', $phpunit);

    $checksum = new Process(['cksum', 'phpunit.xml'], $path);
    $checksum->mustRun();
    file_put_contents($path.'/.codex/local-environment-state/phpunit.xml.cksum', $checksum->getOutput());

    $herdLog = temp_file('herd-log');
    $fakeBin = $path.'/fake-bin';

    mkdir($fakeBin, 0755, true);

    run_local_environment($path, 'cleanup', $fakeBin, $herdLog)->mustRun();

    expect(file_get_contents($path.'/phpunit.xml'))->toBe($phpunit)
        ->and($path.'/.codex/local-environment-state')->not->toBeDirectory();
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

test('codex cleanup uses recorded sqlite database targets when env changes after setup', function (): void {
    $path = temp_directory('ai-harness-cleanup-recorded-sqlite');

    file_put_contents($path.'/.env.example', implode("\n", [
        'APP_URL=http://example.test',
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

    $generatedDatabase = $path.'/database/'.expected_worktree_database_name($path).'.sqlite';
    $generatedTestingDatabase = $path.'/database/'.expected_worktree_testing_database_name($path).'.sqlite';
    $manualDatabase = $path.'/database/manual.sqlite';
    $manualTestingDatabase = $path.'/database/manual_testing.sqlite';

    file_put_contents($manualDatabase, '');
    file_put_contents($manualTestingDatabase, '');
    file_put_contents($path.'/.env', implode("\n", [
        'APP_URL=http://changed.test',
        'DB_CONNECTION=sqlite',
        'DB_DATABASE=database/manual.sqlite',
        'AI_HARNESS_TEST_DB_DATABASE=database/manual_testing.sqlite',
        '',
    ]));

    run_local_environment($path, 'cleanup', $fakeBin, $herdLog)->mustRun();

    expect($generatedDatabase)->not->toBeFile()
        ->and($generatedTestingDatabase)->not->toBeFile()
        ->and($manualDatabase)->toBeFile()
        ->and($manualTestingDatabase)->toBeFile()
        ->and($path.'/.codex/local-environment-state')->not->toBeDirectory();
});

test('codex cleanup refuses recorded sqlite paths outside the managed worktree databases', function (): void {
    $path = temp_directory('ai-harness-cleanup-recorded-sqlite-refusal');
    $outsideDatabase = temp_file('ai-harness-outside-sqlite');

    mkdir($path.'/.codex/local-environment-state', 0755, true);
    file_put_contents($path.'/.codex/local-environment-state/databases.env', implode("\n", [
        'APP_DB_CONNECTION=sqlite',
        'APP_DB_DATABASE='.$outsideDatabase,
        '',
    ]));

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    $herdLog = temp_file('herd-log');
    $fakeBin = $path.'/fake-bin';

    mkdir($fakeBin, 0755, true);

    $process = run_local_environment($path, 'cleanup', $fakeBin, $herdLog);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('refusing to remove unmanaged sqlite database path')
        ->and($outsideDatabase)->toBeFile()
        ->and($path.'/.codex/local-environment-state/databases.env')->toBeFile();
});

test('codex cleanup removes checksum-verified sqlite targets recorded before a database base change', function (): void {
    $path = temp_directory('ai-harness-cleanup-renamed-sqlite');
    $checksum = path_checksum($path);
    $oldAppDatabase = "database/old_database_base_{$checksum}.sqlite";
    $oldTestingDatabase = "database/old_database_base_testing_{$checksum}.sqlite";

    mkdir($path.'/database', 0755, true);
    file_put_contents($path.'/'.$oldAppDatabase, '');
    file_put_contents($path.'/'.$oldTestingDatabase, '');
    mkdir($path.'/.codex/local-environment-state', 0755, true);
    file_put_contents($path.'/.codex/local-environment-state/databases.env', implode("\n", [
        'DATABASE_TARGET=sqlite|'.$oldAppDatabase,
        'DATABASE_TARGET=sqlite|'.$oldTestingDatabase,
        '',
    ]));

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    $herdLog = temp_file('herd-log');
    $fakeBin = $path.'/fake-bin';
    mkdir($fakeBin, 0755, true);

    run_local_environment($path, 'cleanup', $fakeBin, $herdLog)->mustRun();

    expect($path.'/'.$oldAppDatabase)->not->toBeFile()
        ->and($path.'/'.$oldTestingDatabase)->not->toBeFile()
        ->and($path.'/.codex/local-environment-state/databases.env')->not->toBeFile();
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
    file_put_contents($path.'/phpunit.xml', legacy_phpunit_fixture());

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

if [[ "${1:-}" == "compose" && "${2:-}" == "ps" ]]; then
    printf 'laravel.test\n'
    exit 0
fi

exit 1
BASH);
    chmod($fakeBin.'/docker', 0755);

    file_put_contents($fakeBin.'/php', <<<'BASH'
#!/usr/bin/env bash
printf 'bare php should not generate phpunit config when sail is available\n' >&2
exit 44
BASH);
    chmod($fakeBin.'/php', 0755);

    file_put_contents($path.'/vendor/bin/sail', <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$SAIL_LOG"
printf 'database=%s\n' "${AI_HARNESS_DB_DATABASE:-}" >> "$SAIL_LOG"

if [[ "${1:-}" == "php" && -n "${AI_HARNESS_TEST_DB_DATABASE:-}" ]]; then
    shift
    "$REAL_PHP" "$@"
fi
BASH);
    chmod($path.'/vendor/bin/sail', 0755);

    run_local_environment($path, 'setup', $fakeBin, $herdLog, [
        'REAL_PHP' => PHP_BINARY,
        'SAIL_LOG' => $sailLog,
    ])->mustRun();

    expect(file_get_contents($path.'/.env'))
        ->toContain('DB_DATABASE='.expected_worktree_database_name($path))
        ->toContain('AI_HARNESS_TEST_DB_DATABASE='.expected_worktree_testing_database_name($path))
        ->and(file_get_contents($sailLog))
        ->toContain('php -r')
        ->toContain('database='.expected_worktree_database_name($path))
        ->toContain('database='.expected_worktree_testing_database_name($path))
        ->and(file_get_contents($path.'/.ai-harness.phpunit.xml'))
        ->toContain('name="DB_CONNECTION" value="mysql" force="true"')
        ->toContain('name="DB_DATABASE" value="'.expected_worktree_testing_database_name($path).'" force="true"')
        ->toContain('name="DB_URL" value="" force="true"')
        ->and(file_get_contents($path.'/phpunit.xml'))
        ->not()->toContain(expected_worktree_testing_database_name($path));
});

test('codex setup routes composer install through the runtime helper until autoload exists', function (): void {
    $path = temp_directory('ai-harness-composer-runtime');

    file_put_contents($path.'/composer.json', json_encode([
        'require' => [
            'php' => '^8.3',
        ],
    ], JSON_THROW_ON_ERROR));

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

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

if [[ "${1:-}" == "compose" && "${2:-}" == "ps" ]]; then
    printf 'laravel.test\n'
    exit 0
fi

exit 1
BASH);
    chmod($fakeBin.'/docker', 0755);

    file_put_contents($fakeBin.'/composer', <<<'BASH'
#!/usr/bin/env bash
printf 'bare composer should not be used when sail is available\n' >&2
exit 44
BASH);
    chmod($fakeBin.'/composer', 0755);

    file_put_contents($path.'/vendor/bin/sail', <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$SAIL_LOG"
BASH);
    chmod($path.'/vendor/bin/sail', 0755);

    run_local_environment($path, 'setup', $fakeBin, $herdLog, [
        'SAIL_LOG' => $sailLog,
    ])->mustRun();

    expect(file_get_contents($sailLog))
        ->toContain('composer install --no-interaction --prefer-dist');
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

if [[ "${1:-}" == "compose" && "${2:-}" == "ps" ]]; then
    printf 'laravel.test\n'
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

test('mysql cleanup uses recorded database targets when env changes after setup', function (): void {
    $path = temp_directory('ai-harness-sail-recorded-cleanup');

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
    file_put_contents($path.'/phpunit.xml', legacy_phpunit_fixture());

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

if [[ "${1:-}" == "compose" && "${2:-}" == "ps" ]]; then
    printf 'laravel.test\n'
    exit 0
fi

exit 1
BASH);
    chmod($fakeBin.'/docker', 0755);

    file_put_contents($path.'/vendor/bin/sail', <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$SAIL_LOG"
printf 'database=%s\n' "${AI_HARNESS_DB_DATABASE:-}" >> "$SAIL_LOG"

if [[ "${1:-}" == "php" && -n "${AI_HARNESS_TEST_DB_DATABASE:-}" ]]; then
    shift
    "$REAL_PHP" "$@"
fi
BASH);
    chmod($path.'/vendor/bin/sail', 0755);

    run_local_environment($path, 'setup', $fakeBin, $herdLog, [
        'REAL_PHP' => PHP_BINARY,
        'SAIL_LOG' => $sailLog,
    ])->mustRun();

    file_put_contents($sailLog, '');
    file_put_contents($path.'/.env', implode("\n", [
        'DB_CONNECTION=mysql',
        'DB_HOST=mysql',
        'DB_PORT=3306',
        'DB_DATABASE=changed_app_database',
        'DB_USERNAME=sail',
        'DB_PASSWORD=password',
        'AI_HARNESS_TEST_DB_DATABASE=changed_testing_database',
        '',
    ]));

    run_local_environment($path, 'cleanup', $fakeBin, $herdLog, [
        'REAL_PHP' => PHP_BINARY,
        'SAIL_LOG' => $sailLog,
    ])->mustRun();

    expect(file_get_contents($sailLog))
        ->toContain('database='.expected_worktree_database_name($path))
        ->toContain('database='.expected_worktree_testing_database_name($path))
        ->not()->toContain('database=changed_app_database')
        ->not()->toContain('database=changed_testing_database')
        ->and($path.'/.codex/local-environment-state')->not->toBeDirectory();
});

test('codex local environment runs from the generated worktree checkout', function (): void {
    $path = temp_directory('ai-harness-environment');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    expect(file_get_contents($path.'/.codex/environments/environment.toml'))
        ->toContain('WORKTREE_PROFILE=codex \\')
        ->toContain('bash .codex/scripts/local-environment.sh setup')
        ->toContain('bash .codex/scripts/local-environment.sh cleanup')
        ->not()->toContain('CODEX_SOURCE_TREE_PATH')
        ->not()->toContain('CODEX_WORKTREE_PATH');
});

/**
 * @param  array<string, string>  $environment
 */
function run_local_environment(string $path, string $action, string $fakeBin, string $herdLog, array $environment = []): Process
{
    $defaults = [
        'CODEX_WORKTREE_PATH' => $path,
        'HERD_LOG' => $herdLog,
        'PATH' => $fakeBin.PATH_SEPARATOR.getenv('PATH'),
        'WORKTREE_PROFILE' => 'codex',
    ];

    return new Process(
        ['bash', $path.'/.codex/scripts/local-environment.sh', $action],
        $path,
        array_merge($defaults, $environment),
    );
}

function write_fake_herd(string $path, string $extra = ''): string
{
    $fakeBin = $path.'/fake-bin';
    $script = <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$HERD_LOG"

BASH;

    $script .= $extra;
    $script .= <<<'BASH'

if [[ "${1:-}" == "php" && -n "${AI_HARNESS_TEST_DB_DATABASE:-}" ]]; then
    shift
    "$REAL_PHP" "$@"
fi
BASH;

    mkdir($fakeBin, 0755, true);
    file_put_contents($fakeBin.'/herd', $script);
    chmod($fakeBin.'/herd', 0755);

    return $fakeBin;
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

function legacy_phpunit_fixture(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit>
    <php>
        <env name="DB_CONNECTION" value="sqlite"/>
        <env name="DB_DATABASE" value=":memory:"/>
    </php>
</phpunit>
XML;
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
printf 'DB_CONNECTION=%s DB_DATABASE=%s DB_URL=%s\n' "${DB_CONNECTION:-}" "${DB_DATABASE:-}" "${DB_URL:-}" >> artisan.log
BASH);
    chmod($path.'/.dev/bin/ai-harness', 0755);
}
