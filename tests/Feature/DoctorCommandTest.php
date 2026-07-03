<?php

use Symfony\Component\Process\Process;

test('doctor command succeeds when selected harness files exist', function (): void {
    $path = temp_directory('ai-harness');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    pending_artisan('ai-harness:doctor', [
        '--path' => $path,
    ])
        ->expectsOutputToContain('Agents: claude, codex, cursor')
        ->expectsOutputToContain('Runtimes: bare, herd, sail')
        ->expectsOutputToContain('AI harness looks healthy.')
        ->assertSuccessful();
});

test('doctor command fails when a selected harness file is missing', function (): void {
    $path = temp_directory('ai-harness');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    unlink($path.'/AGENTS.md');

    pending_artisan('ai-harness:doctor', [
        '--path' => $path,
    ])
        ->expectsOutputToContain('missing AGENTS.md')
        ->assertFailed();
});

test('doctor command warns when generated harness files drift from rendered stubs', function (): void {
    $path = temp_directory('ai-harness-drift');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
        '--with' => ['herd'],
    ])->assertSuccessful();

    file_put_contents(
        $path.'/.codex/scripts/local-environment.sh',
        (string) file_get_contents($path.'/.codex/scripts/local-environment.sh')."\n# local drift\n",
    );

    pending_artisan('ai-harness:doctor', [
        '--path' => $path,
        '--with' => ['herd'],
    ])
        ->expectsOutputToContain('harness files drifted - commit them')
        ->expectsOutputToContain('drifted .codex/scripts/local-environment.sh')
        ->assertFailed();
});

test('doctor command warns when generated harness files are uncommitted in git', function (): void {
    $path = temp_directory('ai-harness-uncommitted');

    (new Process(['git', 'init'], $path))->mustRun();

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    pending_artisan('ai-harness:doctor', [
        '--path' => $path,
    ])
        ->expectsOutputToContain('harness files drifted - commit them')
        ->expectsOutputToContain('uncommitted AGENTS.md')
        ->assertFailed();
});
