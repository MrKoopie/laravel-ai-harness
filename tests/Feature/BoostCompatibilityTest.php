<?php

test('package exposes boost-discoverable guidelines and skill resources', function (): void {
    $root = dirname(__DIR__, 2);
    $guidelinesPath = $root.'/resources/boost/guidelines/core.blade.php';
    $skillPath = $root.'/resources/boost/skills/laravel-ai-harness/SKILL.md';

    expect($guidelinesPath)->toBeFile()
        ->and($skillPath)->toBeFile();

    $guidelines = file_get_contents($guidelinesPath);
    $skill = file_get_contents($skillPath);

    expect($guidelines)
        ->toBeString()
        ->toContain('# Laravel AI Harness')
        ->toContain('php artisan ai-harness:install')
        ->toContain('<!-- ai-harness:start -->')
        ->toContain('<laravel-boost-guidelines>')
        ->and($skill)
        ->toBeString()
        ->toContain('name: laravel-ai-harness')
        ->toContain('description:')
        ->toContain('php artisan boost:update --discover')
        ->toContain('composer analyse');
});
