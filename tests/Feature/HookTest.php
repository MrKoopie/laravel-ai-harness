<?php

declare(strict_types=1);
use MrKoopie\LaravelAiHarness\Environment\SiteName;

test('Claude EnterWorktree payload prepares the provided worktree without project scripts', function (): void {
    $container = temp_directory('harness-hook');
    $worktree = $container.'/.claude/worktrees/example';
    mkdir($worktree.'/vendor', 0755, true);
    file_put_contents($worktree.'/vendor/autoload.php', "<?php\n");
    file_put_contents($worktree.'/.ai-harness.config', "runtime=native\nservices=none\nagents=claude\nworktrees=true\n");
    file_put_contents($worktree.'/.env.example', "APP_KEY=present\n");

    $process = harness_process(['hook', 'claude', 'enter-worktree'], package_root(), [
        'CLAUDE_PROJECT_DIR' => $container,
    ]);
    $process->setInput(json_encode([
        'tool_response' => ['worktreePath' => $worktree],
    ], JSON_THROW_ON_ERROR));
    $process->mustRun();

    expect($worktree.'/.env')->toBeFile()
        ->and($worktree.'/.claude/scripts/worktree-up.sh')->not->toBeFile();
});

test('Claude EnterWorktree accepts the documented snake-case worktree response', function (): void {
    $container = temp_directory('harness-hook-snake-case');
    $worktree = $container.'/.claude/worktrees/example';
    mkdir($worktree.'/vendor', 0755, true);
    file_put_contents($worktree.'/vendor/autoload.php', "<?php\n");
    file_put_contents($worktree.'/.ai-harness.config', "runtime=native\nservices=none\nagents=claude\nworktrees=true\n");
    file_put_contents($worktree.'/.env.example', "APP_KEY=present\n");

    $process = harness_process(['hook', 'claude', 'enter-worktree'], package_root(), [
        'CLAUDE_PROJECT_DIR' => $container,
    ]);
    $process->setInput(json_encode([
        'tool_response' => ['worktree_path' => $worktree],
    ], JSON_THROW_ON_ERROR));
    $process->mustRun();

    expect($worktree.'/.env')->toBeFile();
});

test('Claude SessionStart is a no-op outside Claude worktrees', function (): void {
    $root = temp_directory('harness-hook-main');
    mkdir($root.'/vendor', 0755, true);
    file_put_contents($root.'/vendor/autoload.php', "<?php\n");
    file_put_contents($root.'/.ai-harness.config', "runtime=native\nservices=none\nagents=claude\nworktrees=true\n");
    file_put_contents($root.'/.env.example', "APP_KEY=present\n");

    $process = harness_process(['hook', 'claude', 'session-start'], package_root());
    $process->setInput(json_encode(['cwd' => $root], JSON_THROW_ON_ERROR));
    $process->mustRun();

    expect($process->getOutput())->toContain('did not identify a worktree')
        ->and($root.'/.env')->not->toBeFile();
});

test('Claude hooks respect worktree automation being disabled', function (): void {
    $container = temp_directory('harness-hook-disabled');
    $worktree = $container.'/.claude/worktrees/example';
    mkdir($worktree, 0755, true);
    file_put_contents($worktree.'/.ai-harness.config', "runtime=native\nservices=none\nagents=claude\nworktrees=false\n");
    file_put_contents($worktree.'/.env.example', "APP_KEY=present\n");

    $process = harness_process(['hook', 'claude', 'enter-worktree'], package_root(), [
        'CLAUDE_PROJECT_DIR' => $container,
    ]);
    $process->setInput(json_encode(['tool_response' => ['worktreePath' => $worktree]], JSON_THROW_ON_ERROR));
    $process->mustRun();

    expect($process->getOutput())->toContain('Worktree automation is disabled')
        ->and($worktree.'/.env')->not->toBeFile();
});

test('Claude WorktreeRemove cleans the provided harness-owned worktree', function (): void {
    $container = temp_directory('harness-hook-remove');
    $worktree = $container.'/.claude/worktrees/example';
    $fakeBin = $container.'/fake-bin';
    $herdLog = temp_file('harness-hook-herd');
    $site = SiteName::forPath($worktree);
    mkdir($worktree, 0755, true);
    mkdir($fakeBin, 0755, true);
    file_put_contents($worktree.'/.ai-harness.config', "runtime=herd\nservices=none\nagents=claude\nworktrees=true\n");
    file_put_contents($worktree.'/.ai-harness.state.json', json_encode([
        'herd_site' => $site,
        'herd_secured' => true,
    ], JSON_THROW_ON_ERROR));
    write_executable($fakeBin.'/herd', <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "${HERD_LOG}"
BASH);

    $process = harness_process(['hook', 'claude', 'worktree-remove'], package_root(), [
        'CLAUDE_PROJECT_DIR' => $container,
        'PATH' => $fakeBin.PATH_SEPARATOR.getenv('PATH'),
        'HERD_LOG' => $herdLog,
    ]);
    $process->setInput(json_encode(['worktree_path' => $worktree], JSON_THROW_ON_ERROR));
    $process->mustRun();

    expect(file($herdLog, FILE_IGNORE_NEW_LINES))->toBe([
        'unsecure '.$site,
        'unlink '.$site,
    ])->and($worktree.'/.ai-harness.state.json')->not->toBeFile();
});

test('Claude WorktreeRemove unlinks Herd when Sail is no longer available', function (): void {
    $container = temp_directory('harness-hook-remove-no-sail');
    $worktree = $container.'/.claude/worktrees/example';
    $fakeBin = $container.'/fake-bin';
    $herdLog = temp_file('harness-hook-herd-no-sail');
    $site = SiteName::forPath($worktree);
    mkdir($worktree, 0755, true);
    mkdir($fakeBin, 0755, true);
    file_put_contents($worktree.'/.ai-harness.config', "runtime=herd\nservices=sail\nagents=claude\nsail_services=mysql\nworktrees=true\n");
    file_put_contents($worktree.'/.ai-harness.state.json', json_encode([
        'herd_site' => $site,
        'herd_secured' => true,
        'mysql_databases' => true,
    ], JSON_THROW_ON_ERROR));
    write_executable($fakeBin.'/herd', <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "${HERD_LOG}"
BASH);

    $process = harness_process(['hook', 'claude', 'worktree-remove'], package_root(), [
        'CLAUDE_PROJECT_DIR' => $container,
        'PATH' => $fakeBin.PATH_SEPARATOR.getenv('PATH'),
        'HERD_LOG' => $herdLog,
    ]);
    $process->setInput(json_encode(['worktree_path' => $worktree], JSON_THROW_ON_ERROR));
    $process->mustRun();

    expect($process->getOutput())->toContain('Skipping MySQL cleanup because Laravel Sail is unavailable')
        ->and(file($herdLog, FILE_IGNORE_NEW_LINES))->toBe([
            'unsecure '.$site,
            'unlink '.$site,
        ])
        ->and(json_decode((string) file_get_contents($worktree.'/.ai-harness.state.json'), true, flags: JSON_THROW_ON_ERROR))
        ->toBe(['mysql_databases' => true]);
});

test('Claude hook input is bounded', function (): void {
    $process = harness_process(['hook', 'claude', 'session-start'], package_root());
    $process->setInput(str_repeat('x', 1_048_577));
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput().$process->getOutput())->toContain('payload exceeds 1 MiB');
});

test('Claude hook targets cannot escape the project worktree directory', function (): void {
    $project = temp_directory('harness-hook-project');
    $outside = temp_directory('harness-hook-outside');
    file_put_contents($outside.'/.ai-harness.config', "runtime=native\nservices=none\nagents=claude\nworktrees=true\n");

    $process = harness_process(['hook', 'claude', 'enter-worktree'], package_root(), [
        'CLAUDE_PROJECT_DIR' => $project,
    ]);
    $process->setInput(json_encode(['tool_response' => ['worktreePath' => $outside]], JSON_THROW_ON_ERROR));
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput().$process->getOutput())->toContain('outside the project worktree');
});
