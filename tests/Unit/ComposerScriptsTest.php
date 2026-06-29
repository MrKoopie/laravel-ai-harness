<?php

use MrKoopie\LaravelAiHarness\Generation\ComposerScripts;

test('it installs auto update hooks without removing existing scripts', function (): void {
    $path = temp_file('composer-json');

    file_put_contents($path, json_encode([
        'scripts' => [
            'post-update-cmd' => [
                '@php artisan package:discover --ansi',
            ],
        ],
    ], JSON_PRETTY_PRINT));

    (new ComposerScripts)->ensureAutoUpdateHooks($path);

    $composer = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['post-install-cmd'])
        ->toContain('@php artisan ai-harness:update --ansi')
        ->and($composer['scripts']['post-update-cmd'])
        ->toContain('@php artisan package:discover --ansi')
        ->toContain('@php artisan ai-harness:update --ansi');
});

test('it does not duplicate auto update hooks', function (): void {
    $path = temp_file('composer-json');

    file_put_contents($path, json_encode([
        'scripts' => [
            'post-install-cmd' => [
                '@php artisan ai-harness:update --ansi',
            ],
        ],
    ], JSON_PRETTY_PRINT));

    (new ComposerScripts)->ensureAutoUpdateHooks($path);
    (new ComposerScripts)->ensureAutoUpdateHooks($path);

    $composer = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['post-install-cmd'])
        ->toBe(['@php artisan ai-harness:update --ansi']);
});

test('it preserves selected optional features in auto update hooks', function (): void {
    $path = temp_file('composer-json');

    file_put_contents($path, json_encode([
        'scripts' => [
            'post-install-cmd' => [
                '@php artisan ai-harness:update --ansi',
            ],
        ],
    ], JSON_PRETTY_PRINT));

    (new ComposerScripts)->ensureAutoUpdateHooks($path, ['polyscope', 'docker']);

    $composer = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['post-install-cmd'])
        ->toBe(['@php artisan ai-harness:update --ansi --with=docker --with=polyscope'])
        ->and($composer['scripts']['post-update-cmd'])
        ->toBe(['@php artisan ai-harness:update --ansi --with=docker --with=polyscope']);
});
