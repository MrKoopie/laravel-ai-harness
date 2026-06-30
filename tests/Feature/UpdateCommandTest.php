<?php

use Symfony\Component\Process\Process;

test('update command writes the initial harness files', function (): void {
    $path = temp_directory('ai-harness');

    file_put_contents($path.'/.gitignore', "/.codex\n/.claude\n");

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    $gitignore = file_get_contents($path.'/.gitignore');

    expect($path.'/AGENTS.md')->toBeFile()
        ->and(file_get_contents($path.'/AGENTS.md'))->toContain('<!-- ai-harness:start -->')
        ->and($path.'/CLAUDE.md')->toBeFile()
        ->and($path.'/.ai/mcp/mcp.json')->toBeFile()
        ->and($path.'/.dev/bin/ai-harness')->toBeFile()
        ->and(file_get_contents($path.'/.dev/bin/ai-harness'))
        ->toContain('script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"')
        ->toContain('repo_root="${CODEX_WORKTREE_PATH:-$(cd -- "${script_dir}/../.." && pwd -P)}"')
        ->toContain('sail_runtime_available()')
        ->toContain('docker info >/dev/null 2>&1')
        ->toContain('podman info >/dev/null 2>&1')
        ->and(is_executable($path.'/.dev/bin/ai-harness'))->toBeTrue()
        ->and($path.'/.codex/scripts/local-environment.sh')->toBeFile()
        ->and(file_get_contents($path.'/.codex/scripts/local-environment.sh'))
        ->toContain('app_key_missing()')
        ->toContain('artisan key:generate --ansi')
        ->toContain('artisan ai-harness:doctor')
        ->not()->toContain('php artisan key:generate --ansi')
        ->not()->toContain('ai-harness:doctor || true')
        ->and(is_executable($path.'/.codex/scripts/local-environment.sh'))->toBeTrue()
        ->and($path.'/.codex/config.toml')->toBeFile()
        ->and(file_get_contents($path.'/.codex/config.toml'))
        ->toContain('# ai-harness:start')
        ->toContain('boost:mcp')
        ->toContain('# ai-harness:end')
        ->and($path.'/.agents/skills/laravel-ai-harness/SKILL.md')->toBeFile()
        ->and($gitignore)
        ->toContain('# ai-harness:start')
        ->toContain('!/.codex/')
        ->toContain('!/.codex/config.toml')
        ->toContain('!/.codex/scripts/local-environment.sh')
        ->toContain('!/.claude/')
        ->toContain('!/.claude/settings.json')
        ->toContain('!/.claude/scripts/')
        ->toContain('!/.claude/scripts/worktree-up.sh')
        ->toContain('!/.claude/scripts/worktree-down.sh')
        ->toContain('!/.ai/mcp/mcp.json')
        ->toContain('!/.dev/bin/ai-harness')
        ->not()->toContain('!/.codex/**')
        ->not()->toContain('!/.claude/**')
        ->not()->toContain('!/.ai/**')
        ->not()->toContain('!/.agents/**')
        ->not()->toContain('!/.dev/**')
        ->and($path.'/polyscope.json')->not()->toBeFile()
        ->and($path.'/docker/mysql/init/10-create-testing-database.sh')->not()->toBeFile();
});

test('claude settings reference generated worktree scripts', function (): void {
    $path = temp_directory('ai-harness');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    $settings = json_decode((string) file_get_contents($path.'/.claude/settings.json'), true, flags: JSON_THROW_ON_ERROR);
    $commands = [];

    array_walk_recursive($settings, function (mixed $value, mixed $key) use (&$commands): void {
        if ($key === 'command' && is_string($value)) {
            $commands[] = $value;
        }
    });

    expect($commands)
        ->toContain('"$CLAUDE_PROJECT_DIR/.claude/scripts/worktree-up.sh"')
        ->toContain('"$CLAUDE_PROJECT_DIR/.claude/scripts/worktree-down.sh"')
        ->toContain('"$CLAUDE_PROJECT_DIR/.dev/bin/ai-harness" ai-harness:doctor')
        ->and($path.'/.claude/scripts/worktree-up.sh')->toBeFile()
        ->and($path.'/.claude/scripts/worktree-down.sh')->toBeFile()
        ->and(is_executable($path.'/.claude/scripts/worktree-up.sh'))->toBeTrue()
        ->and(is_executable($path.'/.claude/scripts/worktree-down.sh'))->toBeTrue();
});

test('codex session hook provisions only codex managed worktrees', function (): void {
    $root = temp_directory('ai-harness-codex-hook');
    $codexHome = $root.'/codex-home';
    $worktree = $codexHome.'/worktrees/abcd/project';
    $localCheckout = $root.'/source/project';
    $log = temp_file('codex-hook-log');

    mkdir($worktree, 0755, true);
    mkdir($localCheckout, 0755, true);

    foreach ([$worktree, $localCheckout] as $path) {
        pending_artisan('ai-harness:update', [
            '--path' => $path,
        ])->assertSuccessful();

        (new Process(['git', 'init'], $path))->mustRun();

        file_put_contents($path.'/.codex/scripts/local-environment.sh', <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

{
    printf 'action=%s\n' "${1:-}"
    printf 'profile=%s\n' "${WORKTREE_PROFILE:-}"
    printf 'path=%s\n' "${CODEX_WORKTREE_PATH:-}"
} >> "${HARNESS_HOOK_LOG}"
BASH);
        chmod($path.'/.codex/scripts/local-environment.sh', 0755);
    }

    $hooks = json_decode((string) file_get_contents($worktree.'/.codex/hooks.json'), true, flags: JSON_THROW_ON_ERROR);
    $command = $hooks['hooks']['SessionStart'][0]['hooks'][0]['command'];

    Process::fromShellCommandline($command, $worktree, [
        'CODEX_HOME' => $codexHome,
        'HARNESS_HOOK_LOG' => $log,
        'PATH' => getenv('PATH'),
    ])->mustRun();

    Process::fromShellCommandline($command, $localCheckout, [
        'CODEX_HOME' => $codexHome,
        'HARNESS_HOOK_LOG' => $log,
        'PATH' => getenv('PATH'),
    ])->mustRun();

    expect(file_get_contents($log))
        ->toContain("action=setup\nprofile=codex\npath={$worktree}")
        ->and(substr_count((string) file_get_contents($log), 'action=setup'))->toBe(1);
});

test('update command preserves existing codex project config outside the harness block', function (): void {
    $path = temp_directory('ai-harness');

    mkdir($path.'/.codex', 0755, true);
    file_put_contents($path.'/.codex/config.toml', <<<'TOML'
model = "gpt-5.5"

[features]
hooks = true
TOML);

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    $config = file_get_contents($path.'/.codex/config.toml');

    expect($config)
        ->toContain('model = "gpt-5.5"')
        ->toContain('[features]')
        ->toContain('# ai-harness:start')
        ->toContain('[mcp_servers.laravel-boost]')
        ->toContain('# ai-harness:end')
        ->and(substr_count((string) $config, '[mcp_servers.laravel-boost]'))->toBe(1);
});

test('update command migrates an unmarked generated codex project config into a managed block', function (): void {
    $path = temp_directory('ai-harness');

    mkdir($path.'/.codex', 0755, true);
    file_put_contents($path.'/.codex/config.toml', <<<'TOML'
[mcp_servers.laravel-boost]
command = "sh"
args = ["-lc", 'repo_root="$(git -C "${CODEX_WORKTREE_PATH:-.}" rev-parse --show-toplevel)" && cd "$repo_root" && exec "$repo_root/.dev/bin/ai-harness" boost:mcp']
TOML);

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    $config = file_get_contents($path.'/.codex/config.toml');

    expect($config)
        ->toContain('# ai-harness:start')
        ->toContain('# ai-harness:end')
        ->and(substr_count((string) $config, '[mcp_servers.laravel-boost]'))->toBe(1);
});

test('update command can opt into optional workspace features', function (): void {
    $path = temp_directory('ai-harness');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
        '--with' => ['docker', 'polyscope'],
    ])->assertSuccessful();

    expect($path.'/polyscope.json')->toBeFile()
        ->and(file_get_contents($path.'/polyscope.json'))->toContain('"copyGitignored": false')
        ->and($path.'/docker/mysql/init/10-create-testing-database.sh')->toBeFile()
        ->and(is_executable($path.'/docker/mysql/init/10-create-testing-database.sh'))->toBeTrue();
});

test('update command derives docker database collation from environment examples', function (): void {
    $path = temp_directory('ai-harness');

    file_put_contents($path.'/.env.testing.example', "DB_CHARSET=utf8mb4\nDB_COLLATION=utf8mb4_uca1400_ai_ci\n");

    pending_artisan('ai-harness:update', [
        '--path' => $path,
        '--with' => ['docker'],
    ])->assertSuccessful();

    expect(file_get_contents($path.'/docker/mysql/init/10-create-testing-database.sh'))
        ->toContain('CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci');
});

test('update command derives the PHP target from project composer metadata', function (): void {
    $path = temp_directory('ai-harness');

    file_put_contents($path.'/composer.json', json_encode([
        'require' => [
            'php' => '^8.3',
        ],
    ], JSON_THROW_ON_ERROR));

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    expect(file_get_contents($path.'/AGENTS.md'))->toContain('PHP target: 8.3');
});

test('update command derives the worktree base from git remote metadata', function (): void {
    $path = temp_directory('ai-harness');
    $remoteDirectory = $path.'/.git/refs/remotes/origin';

    mkdir($remoteDirectory, 0755, true);
    file_put_contents($remoteDirectory.'/HEAD', 'ref: refs/remotes/origin/develop');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    expect(file_get_contents($path.'/AGENTS.md'))->toContain('Default worktree base: origin/develop');
});

test('update command can override generated project metadata from config', function (): void {
    config()->set('ai-harness.project.name', 'Billing Desk');
    config()->set('ai-harness.project.slug', 'billing-desk');
    config()->set('ai-harness.project.database_name', 'billing_desk');
    config()->set('ai-harness.project.database_charset', 'utf8mb4');
    config()->set('ai-harness.project.database_collation', 'utf8mb4_uca1400_ai_ci');
    config()->set('ai-harness.project.php_version', '8.4');
    config()->set('ai-harness.project.worktree_base_ref', 'upstream/trunk');

    $path = temp_directory('ai-harness');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
        '--with' => ['docker'],
    ])->assertSuccessful();

    expect(file_get_contents($path.'/AGENTS.md'))
        ->toContain('App: Billing Desk')
        ->toContain('PHP target: 8.4')
        ->toContain('Default worktree base: upstream/trunk')
        ->and(file_get_contents($path.'/.codex/environments/environment.toml'))
        ->toContain('name = "Billing Desk Codex worktree"')
        ->and(file_get_contents($path.'/docker/mysql/init/10-create-testing-database.sh'))
        ->toContain('billing_desk_testing')
        ->toContain('CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci');
});

test('update command preserves existing generated project identity across worktree paths', function (): void {
    $path = temp_directory('ai-harness-temp-worktree');

    file_put_contents($path.'/AGENTS.md', <<<'MARKDOWN'
<!-- ai-harness:start -->
# AI Harness

## Project Context

- App: bill-it
- PHP target: 8.3
- Default worktree base: origin/main
<!-- ai-harness:end -->
MARKDOWN);

    pending_artisan('ai-harness:update', [
        '--path' => $path,
        '--with' => ['docker'],
    ])->assertSuccessful();

    expect(file_get_contents($path.'/AGENTS.md'))
        ->toContain('App: bill-it')
        ->and(file_get_contents($path.'/.codex/environments/environment.toml'))
        ->toContain('name = "bill-it Codex worktree"')
        ->and(file_get_contents($path.'/.codex/hooks.json'))
        ->toContain('"statusMessage": "Provisioning bill-it worktree"')
        ->and(file_get_contents($path.'/docker/mysql/init/10-create-testing-database.sh'))
        ->toContain('bill_it_testing')
        ->not()->toContain('ai_harness_temp_worktree_testing');
});

test('update command renders valid JSON when the app name needs escaping', function (): void {
    $path = temp_directory('ai"harness');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    $hooks = json_decode((string) file_get_contents($path.'/.codex/hooks.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($hooks['hooks']['SessionStart'][0]['hooks'][0]['statusMessage'])
        ->toBe('Provisioning '.basename($path).' worktree');
});

test('install command persists selected optional features in composer hooks', function (): void {
    $path = temp_directory('ai-harness');

    file_put_contents($path.'/composer.json', json_encode([
        'scripts' => [
            'post-update-cmd' => [
                '@php artisan package:discover --ansi',
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    pending_artisan('ai-harness:install', [
        '--path' => $path,
        '--with' => [' docker ', '', 'docker', 'herd', 'polyscope'],
    ])->assertSuccessful();

    $composer = json_decode((string) file_get_contents($path.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $guardedScript = '@php -r "if (file_exists(\'vendor/mrkoopie/laravel-ai-harness\')) { passthru(escapeshellarg(PHP_BINARY).\' artisan ai-harness:update --ansi --with=docker --with=herd --with=polyscope\', $code); exit($code); }"';

    expect($composer['scripts']['post-install-cmd'])
        ->toBe([$guardedScript])
        ->and($composer['scripts']['post-update-cmd'])
        ->toBe([
            '@php artisan package:discover --ansi',
            $guardedScript,
        ]);
});
