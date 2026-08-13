<?php

declare(strict_types=1);

use MrKoopie\LaravelAiHarness\Config\ConfigException;
use MrKoopie\LaravelAiHarness\Config\ConfigLoader;
use MrKoopie\LaravelAiHarness\Environment\Runtime;
use MrKoopie\LaravelAiHarness\Environment\Services;

test('configuration layers from dist through shared and local files', function (): void {
    $root = temp_directory('harness-config');

    file_put_contents($root.'/.ai-harness.config.dist', implode("\n", [
        'runtime=native',
        'services=none',
        'agents=codex',
        'worktrees=false',
    ]));
    file_put_contents($root.'/.ai-harness.config', implode("\n", [
        'runtime=herd',
        'agents=codex,claude',
        'herd_php="8.4"',
    ]));
    file_put_contents($root.'/.ai-harness.config.local', implode("\n", [
        'services=sail',
        'sail_services=mysql, redis',
        'worktrees=on',
    ]));

    $config = (new ConfigLoader)->load($root);

    expect($config->runtime)->toBe(Runtime::Herd)
        ->and($config->services)->toBe(Services::Sail)
        ->and($config->agents)->toBe(['codex', 'claude'])
        ->and($config->sailServices)->toBe(['mysql', 'redis'])
        ->and($config->herdPhp)->toBe('8.4')
        ->and($config->worktrees)->toBeTrue()
        ->and($config->sourceFiles)->toBe([
            '.ai-harness.config.dist',
            '.ai-harness.config',
            '.ai-harness.config.local',
        ]);
});

test('configuration has conservative internal defaults', function (): void {
    $config = (new ConfigLoader)->load(temp_directory('harness-defaults'));

    expect($config->runtime)->toBe(Runtime::Native)
        ->and($config->services)->toBe(Services::None)
        ->and($config->agents)->toBe(['codex', 'claude'])
        ->and($config->sailServices)->toBe([])
        ->and($config->herdSecure)->toBeTrue()
        ->and($config->herdPhp)->toBeNull()
        ->and($config->worktrees)->toBeTrue()
        ->and($config->sourceFiles)->toBe([]);
});

test('unknown and duplicate configuration keys fail clearly', function (string $contents, string $message): void {
    $root = temp_directory('harness-invalid');
    file_put_contents($root.'/.ai-harness.config', $contents);

    expect(fn () => (new ConfigLoader)->load($root))->toThrow(ConfigException::class, $message);
})->with([
    ['runtim=herd', 'Unknown configuration key [runtim]'],
    ["runtime=herd\nruntime=sail", 'Duplicate configuration key [runtime]'],
    ['herd_secure=perhaps', 'herd_secure must be true or false'],
    ['herd_php=latest', 'herd_php must be empty or a major.minor version'],
    ['agents=codex,cursor', 'Unsupported agent [cursor]'],
    ["services=none\nsail_services=mysql", 'sail_services may only be set when services=sail'],
    ['sail_services=mysql,,redis', 'sail_services contains an empty list item'],
    ['runtime="herd', 'Unterminated quoted value'],
]);

test('configuration files are size bounded', function (): void {
    $root = temp_directory('harness-large-config');
    file_put_contents($root.'/.ai-harness.config', str_repeat('#', 65_537));

    expect(fn () => (new ConfigLoader)->load($root))
        ->toThrow(ConfigException::class, 'exceeds 64 KiB');
});
