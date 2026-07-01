<?php

use Symfony\Component\Process\Process;

test('claude worktree setup delegates to the generated local environment script', function (): void {
    $path = temp_directory('ai-harness-claude-up');
    $worktree = $path.'/.claude/worktrees/feature-a';
    $log = temp_file('claude-wrapper-log');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    install_fake_local_environment($worktree);

    $process = new Process(
        [$path.'/.claude/scripts/worktree-up.sh'],
        $path,
        [
            'CLAUDE_PROJECT_DIR' => $path,
            'HARNESS_WRAPPER_LOG' => $log,
        ],
    );
    $process->setInput(json_encode([
        'tool_response' => [
            'worktreePath' => $worktree,
        ],
    ], JSON_THROW_ON_ERROR));
    $process->mustRun();

    expect(file_get_contents($log))
        ->toContain('action=setup')
        ->toContain('profile=codex')
        ->toContain('path='.$worktree);
});

test('claude worktree cleanup delegates to the generated local environment script', function (): void {
    $path = temp_directory('ai-harness-claude-down');
    $worktree = $path.'/.claude/worktrees/feature-a';
    $log = temp_file('claude-wrapper-log');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    install_fake_local_environment($worktree);
    mkdir($worktree.'/.claude', 0755, true);
    file_put_contents($worktree.'/.claude/.ai-harness-worktree-provisioned', '');

    $process = new Process(
        [$path.'/.claude/scripts/worktree-down.sh'],
        $path,
        [
            'CLAUDE_PROJECT_DIR' => $path,
            'HARNESS_WRAPPER_LOG' => $log,
        ],
    );
    $process->setInput(json_encode([
        'cwd' => $worktree,
        'tool_input' => [
            'action' => 'remove',
        ],
    ], JSON_THROW_ON_ERROR));
    $process->mustRun();

    expect(file_get_contents($log))
        ->toContain('action=cleanup')
        ->toContain('profile=codex')
        ->toContain('path='.$worktree)
        ->and($worktree.'/.claude/.ai-harness-worktree-provisioned')->not()->toBeFile();
});

test('claude worktree cleanup tolerates missing worktree context', function (): void {
    $path = temp_directory('ai-harness-claude-down-missing-context');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    $process = new Process(
        [$path.'/.claude/scripts/worktree-down.sh'],
        $path,
        [
            'CLAUDE_PROJECT_DIR' => $path,
        ],
    );
    $process->run();

    expect($process->getExitCode())->toBe(0)
        ->and($process->getErrorOutput())->toContain('could not determine worktree path');
});

test('claude worktree cleanup removes marker and exits zero when local environment is unavailable', function (): void {
    $path = temp_directory('ai-harness-claude-down-missing-local-env');
    $worktree = $path.'/.claude/worktrees/feature-a';

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    mkdir($worktree.'/.claude', 0755, true);
    file_put_contents($worktree.'/.claude/.ai-harness-worktree-provisioned', '');

    $process = new Process(
        [$path.'/.claude/scripts/worktree-down.sh', $worktree],
        $path,
        [
            'CLAUDE_PROJECT_DIR' => $path,
        ],
    );
    $process->run();

    expect($process->getExitCode())->toBe(0)
        ->and($process->getErrorOutput())->toContain('missing readable local environment script')
        ->and($worktree.'/.claude/.ai-harness-worktree-provisioned')->not()->toBeFile();
});

test('claude worktree cleanup removes marker and exits zero when local environment cleanup fails', function (): void {
    $path = temp_directory('ai-harness-claude-down-cleanup-failure');
    $worktree = $path.'/.claude/worktrees/feature-a';

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    if (! is_dir($worktree.'/.codex/scripts')) {
        mkdir($worktree.'/.codex/scripts', 0755, true);
    }

    file_put_contents($worktree.'/.codex/scripts/local-environment.sh', <<<'BASH'
#!/usr/bin/env bash
exit 42
BASH);
    chmod($worktree.'/.codex/scripts/local-environment.sh', 0755);
    mkdir($worktree.'/.claude', 0755, true);
    file_put_contents($worktree.'/.claude/.ai-harness-worktree-provisioned', '');

    $process = new Process(
        [$path.'/.claude/scripts/worktree-down.sh', $worktree],
        $path,
        [
            'CLAUDE_PROJECT_DIR' => $path,
        ],
    );
    $process->run();

    expect($process->getExitCode())->toBe(0)
        ->and($process->getErrorOutput())->toContain('local environment cleanup failed with status 42')
        ->and($worktree.'/.claude/.ai-harness-worktree-provisioned')->not()->toBeFile();
});

test('claude session setup skips the main checkout', function (): void {
    $path = temp_directory('ai-harness-claude-main');
    $log = temp_file('claude-wrapper-log');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    install_fake_local_environment($path);

    $process = new Process(
        [$path.'/.claude/scripts/worktree-up.sh'],
        $path,
        [
            'CLAUDE_PROJECT_DIR' => $path,
            'HARNESS_WRAPPER_LOG' => $log,
        ],
    );
    $process->setInput(json_encode([
        'cwd' => $path,
        'hook_event_name' => 'SessionStart',
    ], JSON_THROW_ON_ERROR));
    $process->mustRun();

    expect(trim((string) file_get_contents($log)))->toBe('');
});

function install_fake_local_environment(string $path): void
{
    if (! is_dir($path.'/.codex/scripts')) {
        mkdir($path.'/.codex/scripts', 0755, true);
    }

    file_put_contents($path.'/.codex/scripts/local-environment.sh', <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

{
    printf 'action=%s\n' "${1:-}"
    printf 'profile=%s\n' "${WORKTREE_PROFILE:-}"
    printf 'path=%s\n' "${CODEX_WORKTREE_PATH:-}"
} >> "${HARNESS_WRAPPER_LOG}"
BASH);

    chmod($path.'/.codex/scripts/local-environment.sh', 0755);
}
