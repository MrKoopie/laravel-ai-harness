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
        ->and(is_executable($path.'/.dev/bin/ai-harness'))->toBeTrue()
        ->and($path.'/.codex/scripts/local-environment.sh')->toBeFile()
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
        ->and($path.'/docker/mysql/init/10-create-testing-database.sh')->toBeFile()
        ->and(is_executable($path.'/docker/mysql/init/10-create-testing-database.sh'))->toBeTrue();
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
    config()->set('ai-harness.project.php_version', '8.4');
    config()->set('ai-harness.project.worktree_base_ref', 'upstream/trunk');

    $path = temp_directory('ai-harness');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    expect(file_get_contents($path.'/AGENTS.md'))
        ->toContain('PHP target: 8.4')
        ->toContain('Default worktree base: upstream/trunk');
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

    expect($composer['scripts']['post-install-cmd'])
        ->toBe(['@php artisan ai-harness:update --ansi --with=docker --with=polyscope'])
        ->and($composer['scripts']['post-update-cmd'])
        ->toBe([
            '@php artisan package:discover --ansi',
            '@php artisan ai-harness:update --ansi --with=docker --with=polyscope',
        ]);
});
