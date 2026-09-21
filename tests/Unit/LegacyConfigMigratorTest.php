<?php

declare(strict_types=1);

use MrKoopie\LaravelAiHarness\Config\ConfigLoader;
use MrKoopie\LaravelAiHarness\Environment\Runtime;
use MrKoopie\LaravelAiHarness\Environment\Services;
use MrKoopie\LaravelAiHarness\Files\LegacyConfigMigrator;
use MrKoopie\LaravelAiHarness\Files\SafeWriter;

test('legacy configuration is conservatively translated when no new config exists', function (): void {
    $root = temp_directory('harness-legacy-config');
    $migrator = new LegacyConfigMigrator(new SafeWriter);

    $result = $migrator->migrate(
        $root,
        [
            'codex' => true,
            'claude' => false,
            'herd' => true,
            'docker' => true,
            'skills' => true,
            'polyscope' => true,
        ],
        ['php_version' => '8.4'],
        [],
    );
    $config = (new ConfigLoader)->load($root);

    expect($result->created)->toBeTrue()
        ->and($config->runtime)->toBe(Runtime::Herd)
        ->and($config->services)->toBe(Services::Sail)
        ->and($config->sailServices)->toBe(['mysql'])
        ->and($config->agents)->toBe(['codex'])
        ->and($config->herdPhp)->toBe('8.4')
        ->and(implode("\n", $result->warnings))->toContain('skills')
        ->toContain('polyscope');
});

test('legacy command flags are translated but never overwrite a new config', function (): void {
    $root = temp_directory('harness-legacy-flags');
    $migrator = new LegacyConfigMigrator(new SafeWriter);

    $first = $migrator->migrate($root, [], [], ['herd', 'docker']);
    $contents = (string) file_get_contents($root.'/.ai-harness.config');
    $second = $migrator->migrate($root, ['codex' => false], [], []);

    expect($first->created)->toBeTrue()
        ->and($second->created)->toBeFalse()
        ->and(file_get_contents($root.'/.ai-harness.config'))->toBe($contents)
        ->and($contents)->toContain('runtime=herd')
        ->and($contents)->toContain('services=sail');
});

test('legacy migration reports obsolete package artifacts without deleting them', function (): void {
    $root = temp_directory('harness-legacy-artifacts');
    mkdir($root.'/.codex', 0755, true);
    file_put_contents($root.'/.codex/hooks.json', "{}\n");

    $result = (new LegacyConfigMigrator(new SafeWriter))->migrate($root, [], [], []);

    expect($root.'/.codex/hooks.json')->toBeFile()
        ->and(implode("\n", $result->warnings))->toContain('.codex/hooks.json');
});
