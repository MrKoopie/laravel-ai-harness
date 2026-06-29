<?php

test('update command writes the initial harness files', function (): void {
    $path = temp_directory('ai-harness');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

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
        ->and($path.'/.agents/skills/laravel-ai-harness/SKILL.md')->toBeFile()
        ->and($path.'/polyscope.json')->not()->toBeFile()
        ->and($path.'/docker/mysql/init/10-create-testing-database.sh')->not()->toBeFile();
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
        '--with' => [' docker ', '', 'docker', 'polyscope'],
    ])->assertSuccessful();

    $composer = json_decode((string) file_get_contents($path.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $guardedScript = '@php -r "if (file_exists(\'vendor/mrkoopie/laravel-ai-harness\')) { passthru(PHP_BINARY.\' artisan ai-harness:update --ansi --with=docker --with=polyscope\', $code); exit($code); }"';

    expect($composer['scripts']['post-install-cmd'])
        ->toBe([$guardedScript])
        ->and($composer['scripts']['post-update-cmd'])
        ->toBe([
            '@php artisan package:discover --ansi',
            $guardedScript,
        ]);
});
