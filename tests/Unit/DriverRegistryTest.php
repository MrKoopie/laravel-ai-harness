<?php

use MrKoopie\LaravelAiHarness\Drivers\DriverRegistry;

test('it exposes the initial agent and runtime drivers', function (): void {
    $registry = DriverRegistry::defaults();

    expect($registry->agentNames())
        ->toBe(['claude', 'codex', 'cursor'])
        ->and($registry->runtimeNames())
        ->toBe(['bare', 'herd', 'sail']);
});

test('it resolves configured agent paths', function (): void {
    $registry = DriverRegistry::defaults();

    expect($registry->agent('codex')->guidelinesPath())->toBe('AGENTS.md')
        ->and($registry->agent('claude')->guidelinesPath())->toBe('CLAUDE.md')
        ->and($registry->agent('cursor')->skillsPath())->toBe('.cursor/skills');
});

test('runtime drivers preserve user argument spacing', function (): void {
    $registry = DriverRegistry::defaults();

    expect($registry->runtime('sail')->artisanCommand('--message="foo  bar"'))
        ->toBe('./vendor/bin/sail artisan --message="foo  bar"');
});
