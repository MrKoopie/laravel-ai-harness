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

    expect($log)
        ->toContain('link '.expected_herd_site_name($path).' --no-interaction')
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

    expect($log)
        ->toContain('link '.expected_herd_site_name($firstPath).' --no-interaction')
        ->toContain('link '.expected_herd_site_name($secondPath).' --no-interaction')
        ->and(expected_herd_site_name($firstPath))
        ->not->toBe(expected_herd_site_name($secondPath));
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

test('codex cleanup is run against the generated worktree path', function (): void {
    $path = temp_directory('ai-harness-environment');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    expect(file_get_contents($path.'/.codex/environments/environment.toml'))
        ->toContain(': "${CODEX_WORKTREE_PATH:?CODEX_WORKTREE_PATH is required}"')
        ->toContain('bash "$CODEX_SOURCE_TREE_PATH/.codex/scripts/local-environment.sh" cleanup');
});

function run_local_environment(string $path, string $action, string $fakeBin, string $herdLog): Process
{
    return new Process(
        ['bash', $path.'/.codex/scripts/local-environment.sh', $action],
        $path,
        [
            'CODEX_WORKTREE_PATH' => $path,
            'HERD_LOG' => $herdLog,
            'PATH' => $fakeBin.PATH_SEPARATOR.getenv('PATH'),
            'WORKTREE_PROFILE' => 'codex',
        ],
    );
}

function expected_herd_site_name(string $path): string
{
    $checksum = new Process(['cksum'], null, null, $path);
    $checksum->mustRun();

    if (preg_match('/^(\d+)\s+/', $checksum->getOutput(), $matches) !== 1) {
        throw new RuntimeException('Unable to derive expected Herd site hash.');
    }

    $hash = $matches[1];

    $name = basename($path).'-'.basename(dirname($path)).'-'.$hash;
    $name = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $name));

    return trim($name, '-');
}
