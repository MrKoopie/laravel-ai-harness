<?php

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
